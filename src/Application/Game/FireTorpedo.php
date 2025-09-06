<?php


namespace App\Application\Game;

use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class FireTorpedo
{
    public function __construct(private GameRepository $repo)
    {
    }

    /**
     * @return list<array{x:int,y:int,result:string}>
     */
    public function __invoke(string $gameId, int $x, int $y, Direction $direction): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (null === $game) {
            throw new \DomainException('Game not found');
        }

        $results = $game->fireTorpedo(new Coordinate($x, $y), $direction);

        $this->repo->save($game);

        return $results;
    }
}
