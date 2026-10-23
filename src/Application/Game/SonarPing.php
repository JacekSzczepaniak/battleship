<?php

namespace App\Application\Game;

use App\Domain\Game\BattleAction;
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
     * Radius null = maksymalny promień z rulesetu gry. Sonar kosztuje energię,
     * ale nie kończy tury (#59) — dlatego wymaga tury gracza.
     *
     * @return array{radius:int, cells:list<array{x:int,y:int,occupied:bool}>, energy:array{left:int,max:int,costs:array<string,int>}|null}
     */
    public function __invoke(string $gameId, int $x, int $y, ?int $radius = null): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (null === $game) {
            throw new \DomainException('Game not found');
        }

        if ($game->isFinished()) {
            throw new \DomainException('Game already finished');
        }

        if ('player' !== $game->turn()) {
            throw new \DomainException('Not player turn');
        }

        $effectiveRadius = $radius ?? $game->ruleset()->weapons()->sonar->radius;
        $game->spendEnergy('player', BattleAction::Sonar);
        $results = $game->sonarPing(new Coordinate($x, $y), $radius);

        // zapis: zużycie sonaru i energii
        $this->repo->save($game);

        return ['radius' => $effectiveRadius, 'cells' => $results, 'energy' => $game->energyState()];
    }
}
