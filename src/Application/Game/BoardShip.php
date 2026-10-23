<?php

namespace App\Application\Game;

use App\Domain\Game\BattleAction;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class BoardShip
{
    public function __construct(
        private readonly GameRepository $repo,
        private readonly OpponentTurn $opponentTurn,
    ) {
    }

    /**
     * Abordaż ostatniego statku wroga (#118): ⚡3, czyli cała tura, jedna
     * próba na bitwę. Sukces kończy bitwę wygraną; porażka oddaje turę AI.
     *
     * @return array{
     *   boarding: array{round:int, roll:int, threshold:int, modifiers:list<array{code:string, value:int}>, ship:int, success:bool},
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

        $attempt = $game->attemptBoarding();
        $game->spendEnergy('player', BattleAction::Board);
        $game->setTurn('opponent');

        $turnOutcome = $this->opponentTurn->respond($game);

        $this->repo->save($game);

        return ['boarding' => $attempt->toArray()] + $turnOutcome;
    }
}
