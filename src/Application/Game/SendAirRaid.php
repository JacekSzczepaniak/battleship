<?php

namespace App\Application\Game;

use App\Domain\Game\Area;
use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class SendAirRaid
{
    public function __construct(private GameRepository $repo)
    {
    }

    public function __invoke(string $gameId, int $x, int $y, int $width, int $height): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (null === $game) {
            throw new \DomainException('Game not found');
        }

        $centralPoint = new Coordinate($x, $y);
        $results = $game->sendAirRaid($centralPoint, new Area($centralPoint, $width, $height));

        $this->repo->save($game);
        return $results;
    }
}
