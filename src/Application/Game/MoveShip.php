<?php

namespace App\Application\Game;

use App\Domain\Game\BattleAction;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class MoveShip
{
    public function __construct(private GameRepository $repo)
    {
    }

    /**
     * Ruch statku gracza o 1 pole (#60). Kosztuje 1⚡, ale nie kończy tury —
     * dlatego wymaga tury gracza, jak sonar. Statek wskazuje dowolne jego pole.
     *
     * @return array{
     *   ship: array{x:int,y:int,o:string,l:int},
     *   moveUsed: bool,
     *   energy: array{left:int,max:int,costs:array<string,int>}|null
     * }
     */
    public function __invoke(string $gameId, int $x, int $y, Direction $direction): array
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

        $game->spendEnergy('player', BattleAction::Move);
        $ship = $game->moveShip(new Coordinate($x, $y), $direction);

        $this->repo->save($game);

        return [
            'ship' => [
                'x' => $ship->start->x,
                'y' => $ship->start->y,
                'o' => $ship->orientation->value,
                'l' => $ship->length,
            ],
            'moveUsed' => $game->playerMovedThisTurn(),
            'energy' => $game->energyState(),
        ];
    }
}
