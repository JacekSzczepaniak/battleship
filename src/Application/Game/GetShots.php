<?php

namespace App\Application\Game;

use App\Domain\Game\GameRepository;
use App\Domain\Game\Orientation;
use App\Domain\Shared\GameId;

final class GetShots
{
    public function __construct(private readonly GameRepository $repo)
    {
    }

    /** @return array{finished:bool, shots:array<int, array{x:int,y:int,result:string}>} */
    public function handle(string $gameId): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (!$game) {
            throw new \InvalidArgumentException('Game not found');
        }

        $shots = $game->shotsWithResults();

        // Zbierz trafione pola (hit lub sunk)
        $hitSet = [];
        foreach ($shots as $s) {
            if ($s['result'] === 'hit' || $s['result'] === 'sunk') {
                $hitSet[$s['x'].':'.$s['y']] = true;
            }
        }

        // Gra zakończona, jeśli każde pole każdego statku jest trafione
        $finished = false;
        $fleet = $game->fleet();
        if ($fleet) {
            $allHit = true;
            foreach ($fleet as $ship) {
                for ($i = 0; $i < $ship->length; ++$i) {
                    $x = $ship->start->x + (Orientation::H === $ship->orientation ? $i : 0);
                    $y = $ship->start->y + (Orientation::V === $ship->orientation ? $i : 0);
                    if (!isset($hitSet["$x:$y"])) {
                        $allHit = false;
                        break 2;
                    }
                }
            }
            $finished = $allHit;
        }

        return [
            'finished' => $finished,
            'shots' => $shots,
        ];
    }
}
