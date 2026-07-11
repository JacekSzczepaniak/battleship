<?php

namespace App\Application\Game;

use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class FireShot
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly OpponentTurn $opponentTurn,
    ) {
    }

    /**
     * @return array{
     *   result:string,
     *   win:bool,
     *   loss:bool,
     *   finished:bool,
     *   turn:string,
     *   opponentMoves:list<array{x:int,y:int,result:string}>
     * }
     */
    public function handle(string $gameId, int $x, int $y): array
    {
        $game = $this->repo->get(new GameId($gameId));
        if (!$game) {
            throw new \InvalidArgumentException('Game not found');
        }

        if ($game->isFinished()) {
            throw new \DomainException('Game already finished');
        }

        if ('player' !== $game->turn()) {
            throw new \DomainException('Not player turn');
        }

        // Gracz wykonuje ruch – oznacz turę przeciwnika, dopóki nie skończymy sekwencji
        $game->setTurn('opponent');

        $out = $game->fireShot(new Coordinate($x, $y));

        $turnOutcome = $this->opponentTurn->respond($game);

        $this->repo->save($game);

        return ['result' => $out->value] + $turnOutcome;
    }
}
