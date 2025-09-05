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

        // include shots (with results) if domain exposes them
        $shots = [];
        if (method_exists($game, 'shotsWithResults')) {
            /** @var list<array{x:int,y:int,result:string}> $withResults */
            $withResults = $game->shotsWithResults();
            $shots = array_map(
                static fn (array $s) => ['x' => $s['x'], 'y' => $s['y'], 'r' => $s['result']],
                $withResults
            );
        }

        return [
            'status' => $game->status()->value,
            'ruleset' => [
                'type' => 'classic',
                'board' => ['w' => $size->width, 'h' => $size->height],
            ],
            'fleet' => $fleet,
            'shots' => $shots,
        ];
    }

    public function toDomain(string $id, array $state): Game
    {
        $ruleset = $this->rulesetFromArray($state['ruleset'] ?? []);
        $status = GameStatus::from((string) ($state['status'] ?? GameStatus::Pending->value));
        $game = Game::fromSnapshot(new GameId($id), $ruleset, $status);

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

            // preferred: domain setter (if available)
            if (method_exists($game, 'setShotsFromSnapshot')) {
                $game->setShotsFromSnapshot($shots);
            } else {
                // fallback: reflection — set 'shots' and compute 'hits'
                $ref = new \ReflectionObject($game);

                // build 'x:y' => true map for shots
                $shotMap = [];
                foreach ($shots as $s) {
                    $key = $s['x'].':'.$s['y'];
                    $shotMap[$key] = true;
                }

                // set private $shots if present
                if ($ref->hasProperty('shots')) {
                    $p = $ref->getProperty('shots');
                    $p->setValue($game, $shotMap);
                }

                // compute hits based on current fleet
                $hitMap = [];
                $fleet = $game->fleet() ?? [];
                foreach ($fleet as $ship) {
                    foreach ($this->cellsFor($ship) as $cellKey) {
                        if (isset($shotMap[$cellKey])) {
                            $hitMap[$cellKey] = true;
                        }
                    }
                }

                // set private $hits if present
                if ($ref->hasProperty('hits')) {
                    $p = $ref->getProperty('hits');
                    $p->setValue($game, $hitMap);
                }
            }
        }

        return $game;
    }

    private function rulesetFromArray(array $data): Ruleset
    {
        $board = $data['board'] ?? ['w' => 10, 'h' => 10];

        return new ClassicRuleset(new BoardSize((int) $board['w'], (int) $board['h']));
    }

    /**
     * @return list<string> ship cell keys in "x:y" format
     */
    private function cellsFor(Ship $ship): array
    {
        $out = [];
        for ($i = 0; $i < $ship->length; ++$i) {
            $x = $ship->start->x + (Orientation::H === $ship->orientation ? $i : 0);
            $y = $ship->start->y + (Orientation::V === $ship->orientation ? $i : 0);
            $out[] = $x.':'.$y;
        }

        return $out;
    }
}
