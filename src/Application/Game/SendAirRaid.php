<?php

namespace App\Application\Game;

use App\Domain\Game\Area;
use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class SendAirRaid
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
    public function __invoke(string $gameId, int $x, int $y, int $width, int $height): array
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

        $centralPoint = new Coordinate($x, $y);
        $results = $game->sendAirRaid($centralPoint, new Area($width, $height));

        $turnOutcome = $this->opponentTurn->respond($game);

        $this->repo->save($game);

        return ['results' => $results] + $turnOutcome;
    }
}
