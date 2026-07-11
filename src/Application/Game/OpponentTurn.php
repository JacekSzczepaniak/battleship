<?php

namespace App\Application\Game;

use App\Domain\Game\AI\HuntTargetAI;
use App\Domain\Game\Game;

/**
 * Odpowiedź przeciwnika (AI) po ofensywnym ruchu gracza: jeden strzał
 * + rozliczenie tury i końca gry. Wspólne dla strzału, torpedy i nalotu.
 */
final class OpponentTurn
{
    /**
     * @return array{finished:bool, win:bool, loss:bool, turn:string, opponentMoves:list<array{x:int,y:int,result:string}>}
     */
    public function respond(Game $game): array
    {
        $finished = $game->isFinished();
        $opponentMoves = [];

        if (!$finished) {
            $ai = HuntTargetAI::fromSnapshot($game->aiState());
            $target = $ai->nextShot($game->opponentShotsView());
            $result = $game->fireOpponentShot($target);
            $ai->notify($target, $result);
            $game->setAiState($ai->toSnapshot());
            $opponentMoves[] = ['x' => $target->x, 'y' => $target->y, 'result' => $result->value];

            $finished = $game->isFinished();
        }

        // Po ruchu przeciwnika tura wraca do gracza (o ile gra nie skończona)
        $turn = $finished ? 'none' : 'player';
        $game->setTurn($turn);

        return [
            'finished' => $finished,
            'win' => 'won' === $game->status()->value,
            'loss' => 'lost' === $game->status()->value,
            'turn' => $turn,
            'opponentMoves' => $opponentMoves,
        ];
    }
}
