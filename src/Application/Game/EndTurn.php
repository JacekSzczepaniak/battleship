<?php

namespace App\Application\Game;

use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

/**
 * „Zakończ turę” (#59): gracz oddaje turę bez ataku, np. po samym sonarze.
 * Tylko w bitwie z energią — w classic tura kończy się wyłącznie strzałem.
 */
final class EndTurn
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly OpponentTurn $opponentTurn,
    ) {
    }

    /**
     * @return array{
     *   finished:bool,
     *   win:bool,
     *   loss:bool,
     *   turn:string,
     *   opponentMoves:list<array{x:int,y:int,result:string}>
     * }
     */
    public function __invoke(string $gameId): array
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

        if (!$game->hasEnergy()) {
            throw new \DomainException('End turn requires battle energy');
        }

        $game->setTurn('opponent');
        $turnOutcome = $this->opponentTurn->respond($game);

        $this->repo->save($game);

        return $turnOutcome;
    }
}
