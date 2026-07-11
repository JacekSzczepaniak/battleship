<?php

namespace App\Application\Game;

use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class FireTorpedo
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly OpponentTurn $opponentTurn,
    ) {
    }

    /**
     * @return array{
     *   results:list<array{x:int,y:int,result:string}>,
     *   win:bool,
     *   loss:bool,
     *   finished:bool,
     *   turn:string,
     *   opponentMoves:list<array{x:int,y:int,result:string}>
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

        $game->setTurn('opponent');

        $results = $game->fireTorpedo(new Coordinate($x, $y), $direction);

        $turnOutcome = $this->opponentTurn->respond($game);

        $this->repo->save($game);

        return ['results' => $results] + $turnOutcome;
    }
}
