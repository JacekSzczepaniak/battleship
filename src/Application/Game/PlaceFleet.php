<?php

namespace App\Application\Game;

use App\Application\Ports\GameRepository;
use App\Domain\Game\{Ship, Orientation, Coordinate};
use App\Domain\Shared\GameId;

final class PlaceFleet
{
    public function __construct(private GameRepository $repo)
    {
    }

    /**
     * @param array<int,array{x:int,y:int,o:string,l:int}> $shipsSpec
     */
    public function handle(string $gameId, array $shipsSpec): void
    {
        $game = $this->repo->get(new GameId($gameId));
        if (!$game) {
            throw new \RuntimeException('Game not found');
        }

        $ships = [];
        foreach ($shipsSpec as $s) {
            $ships[] = new Ship(
                new Coordinate((int)$s['x'], (int)$s['y']),
                Orientation::from(strtoupper((string)$s['o'])),
                (int)$s['l']
            );
        }

        $game->placeFleet($ships);
        $this->repo->save($game);
    }
}
