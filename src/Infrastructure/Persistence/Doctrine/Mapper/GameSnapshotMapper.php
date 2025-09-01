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

        // jeśli domena udostępnia listę strzałów — zapiszmy je do snapshotu
        $shots = [];
        if (method_exists($game, 'shots')) {
            /** @var list<array{x:int,y:int}> $shots */
            $shots = $game->shots();
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

        // Flota ze snapshotu
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

        // Strzały ze snapshotu (opcjonalnie) + rekonstrukcja 'hits'
        if (!empty($state['shots']) && is_array($state['shots'])) {
            // 1) normalizuj wejście
            $shots = [];
            foreach ($state['shots'] as $s) {
                if (isset($s['x'], $s['y'])) {
                    $shots[] = [(int) $s['x'], (int) $s['y']];
                }
            }

            // 2) jeśli domena ma dedykowany setter — użyj go
            if (method_exists($game, 'setShotsFromSnapshot')) {
                $game->setShotsFromSnapshot($shots); // preferowane rozwiązanie, jeśli dodałeś taką metodę
            } else {
                // 3) fallback: Reflection — wpisz 'shots' i wylicz 'hits' po flocie
                $ref = new \ReflectionObject($game);

                // budujemy mapę 'x:y' => true
                $shotMap = [];
                foreach ($shots as [$x, $y]) {
                    $shotMap["$x:$y"] = true;
                }

                // a) ustaw prywatne pole $shots, jeśli istnieje
                if ($ref->hasProperty('shots')) {
                    $p = $ref->getProperty('shots');
                    $p->setValue($game, $shotMap);
                }

                // b) wylicz trafienia na podstawie floty
                $hitMap = [];
                $fleet = $game->fleet() ?? [];
                foreach ($fleet as $ship) {
                    foreach ($this->cellsFor($ship) as $cellKey) {
                        if (isset($shotMap[$cellKey])) {
                            $hitMap[$cellKey] = true;
                        }
                    }
                }

                // c) ustaw prywatne pole $hits, jeśli istnieje
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
     * @return list<string> klucze pól statku w formie "x:y"
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
