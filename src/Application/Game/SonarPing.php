<?php


namespace App\Application\Game;

use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class SonarPing
{
    public function __construct(private GameRepository $repo)
    {
    }

    /**
     * Executes a sonar ping and returns occupancy info for scanned cells.
     *
     * @return list<array{x:int,y:int,occupied:bool}>
     */
    public function __invoke(string $gameId, int $x, int $y, int $radius = 3): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (null === $game) {
            throw new \DomainException('Game not found');
        }

        $results = $game->sonarPing(new Coordinate($x, $y), $radius);

        // Sonar is informational; no state changes to persist aside from potential future logging.
        // Still, save ensures consistency if in the future sonar updates visibility state.
        $this->repo->save($game);

        return $results;
    }
}
