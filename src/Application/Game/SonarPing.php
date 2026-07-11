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
     * Radius null = maksymalny promień z rulesetu gry.
     *
     * @return array{radius:int, cells:list<array{x:int,y:int,occupied:bool}>}
     */
    public function __invoke(string $gameId, int $x, int $y, ?int $radius = null): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (null === $game) {
            throw new \DomainException('Game not found');
        }

        $effectiveRadius = $radius ?? $game->ruleset()->weapons()->sonar->radius;
        $results = $game->sonarPing(new Coordinate($x, $y), $radius);

        // Sonar is informational; no state changes to persist aside from potential future logging.
        // Still, save ensures consistency if in the future sonar updates visibility state.
        $this->repo->save($game);

        return ['radius' => $effectiveRadius, 'cells' => $results];
    }
}
