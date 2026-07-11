<?php

namespace App\Infrastructure\Persistence\Doctrine\Mapper;

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Game;
use App\Domain\Game\GameStatus;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ruleset;
use App\Domain\Game\Ship;
use App\Domain\Shared\GameId;

final class GameSnapshotMapper
{
    public function toArray(Game $game): array
    {
        $size = $game->ruleset()->boardSize();

        $fleet = null;
        if (null !== $game->fleet()) {
            $fleet = array_map(function (Ship $s) {
                return [
                    'x' => $s->start->x,
                    'y' => $s->start->y,
                    'o' => $s->orientation->value,
                    'l' => $s->length,
                ];
            }, $game->fleet());
        }

        // opponent fleet (optional)
        $opponentFleet = null;
        if (null !== $game->opponentFleet()) {
            $opponentFleet = array_map(function (Ship $s) {
                return [
                    'x' => $s->start->x,
                    'y' => $s->start->y,
                    'o' => $s->orientation->value,
                    'l' => $s->length,
                ];
            }, $game->opponentFleet());
        }

        // shots with results
        $shots = array_map(
            static fn (array $s) => ['x' => $s['x'], 'y' => $s['y'], 'r' => $s['result']],
            $game->shotsWithResults()
        );

        return [
            'status' => $game->status()->value,
            'ruleset' => [
                'type' => 'classic',
                'board' => ['w' => $size->width, 'h' => $size->height],
            ],
            'fleet' => $fleet,
            'shots' => $shots,
            // opponent shots (AI) snapshot
            'opponentShots' => array_map(
                static fn (array $s) => ['x' => $s['x'], 'y' => $s['y'], 'r' => $s['result']],
                $game->opponentShotsWithResults()
            ),
            // opponent fleet snapshot – optional
            'opponentFleet' => $opponentFleet,
            // Stan AI przeciwnika (kształt zna HuntTargetAI) – opcjonalnie
            'ai' => [] !== $game->aiState() ? $game->aiState() : null,
            // Iteration 1 meta
            'mode' => $game->mode(),
            'opponent' => $game->opponent(),
            'turn' => $game->turn(),
        ];
    }

    public function toDomain(string $id, array $state): Game
    {
        $ruleset = $this->rulesetFromArray($state['ruleset'] ?? []);
        $statusVal = (string) ($state['status'] ?? GameStatus::Pending->value);
        try {
            $status = GameStatus::from($statusVal);
        } catch (\Throwable) {
            $status = GameStatus::Pending;
        }
        $game = Game::fromSnapshot(new GameId($id), $ruleset, $status);

        // meta (optional in older snapshots)
        if (isset($state['mode']) && is_string($state['mode'])) {
            $game->setMode($state['mode']);
        }
        if (isset($state['opponent']) && is_string($state['opponent'])) {
            $game->setOpponent($state['opponent']);
        }
        if (isset($state['turn']) && is_string($state['turn'])) {
            $turn = $state['turn'];
            $game->setTurn(in_array($turn, ['player', 'opponent', 'none'], true) ? $turn : 'player');
        }

        // fleet from snapshot
        if (isset($state['fleet']) && is_array($state['fleet'])) {
            $ships = [];
            foreach ($state['fleet'] as $s) {
                $ships[] = new Ship(
                    new Coordinate((int) $s['x'], (int) $s['y']),
                    Orientation::from((string) $s['o']),
                    (int) $s['l']
                );
            }
            $game->setFleetFromSnapshot($ships);
        }

        // opponent fleet from snapshot (optional) — PRZED strzałami gracza:
        // strzały są własnością strony, w którą oddano (targetSide), więc
        // strona przeciwnika musi istnieć zanim je odtworzymy
        if (!empty($state['opponentFleet']) && is_array($state['opponentFleet'])) {
            $ships = [];
            foreach ($state['opponentFleet'] as $s) {
                if (isset($s['x'], $s['y'], $s['o'], $s['l'])) {
                    $ships[] = new Ship(
                        new Coordinate((int) $s['x'], (int) $s['y']),
                        Orientation::from((string) $s['o']),
                        (int) $s['l']
                    );
                }
            }
            if ([] !== $ships) {
                $game->setOpponentFleetFromSnapshot($ships);
            }
        }

        // shots from snapshot (optional) + hits reconstruction
        if (!empty($state['shots']) && is_array($state['shots'])) {
            // normalize input
            $shots = [];
            foreach ($state['shots'] as $s) {
                if (isset($s['x'], $s['y'])) {
                    $entry = ['x' => (int) $s['x'], 'y' => (int) $s['y']];
                    if (isset($s['r'])) {
                        $entry['r'] = (string) $s['r']; // 'hit' | 'sunk' | 'miss' | 'duplicate'
                    }
                    $shots[] = $entry;
                }
            }

            $game->setShotsFromSnapshot($shots);
        }

        // opponent shots from snapshot (optional)
        if (!empty($state['opponentShots']) && is_array($state['opponentShots'])) {
            $oppShots = [];
            foreach ($state['opponentShots'] as $s) {
                if (isset($s['x'], $s['y'])) {
                    $entry = ['x' => (int) $s['x'], 'y' => (int) $s['y']];
                    if (isset($s['r'])) {
                        $entry['r'] = (string) $s['r'];
                    }
                    $oppShots[] = $entry;
                }
            }

            $game->setOpponentShotsFromSnapshot($oppShots);
        }

        // AI state from snapshot (optional; stary kształt zostanie zignorowany przez HuntTargetAI::fromSnapshot)
        if (!empty($state['ai']) && is_array($state['ai'])) {
            $game->setAiState($state['ai']);
        }

        return $game;
    }

    private function rulesetFromArray(array $data): Ruleset
    {
        $board = $data['board'] ?? ['w' => 10, 'h' => 10];

        return new ClassicRuleset(new BoardSize((int) $board['w'], (int) $board['h']));
    }
}
