<?php

namespace App\Application\Game;

use App\Domain\Game\AI\HuntTargetAI;
use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;

final class FireShot
{
    public function __construct(private readonly GameRepository $repo)
    {
    }

    /**
     * Rozszerzona odpowiedź Iteracja 1.
     *
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

        // Wyliczenia po ruchu gracza
        $finished = $game->isFinished();
        $win = 'won' === $game->status()->value;
        $loss = 'lost' === $game->status()->value;

        $opponentMoves = [];

        // Przeciwnik (HuntTargetAI): 1 strzał synchronicznie, tylko gdy gra nie skończona
        if (!$finished) {
            $ai = HuntTargetAI::fromSnapshot($game->aiState());
            $target = $ai->nextShot($game->opponentShotsView());
            $oppResult = $game->fireOpponentShot($target);
            $ai->notify($target, $oppResult);
            $game->setAiState($ai->toSnapshot());
            $opponentMoves[] = ['x' => $target->x, 'y' => $target->y, 'result' => $oppResult->value];

            // Re-ewaluacja zakończenia po ruchu przeciwnika
            $finished = 'lost' === $game->status()->value;
            $win = 'won' === $game->status()->value;
            $loss = 'lost' === $game->status()->value;
        }

        // Po ruchu przeciwnika tura wraca do gracza (o ile gra nie skończona)
        $turn = $finished ? 'none' : 'player';
        $game->setTurn($turn);

        $this->repo->save($game);

        return [
            'result' => $out->value,                     // 'miss' | 'hit' | 'sunk' | 'duplicate'
            'win' => $win,
            'loss' => $loss,
            'finished' => $finished,
            'turn' => $turn,
            'opponentMoves' => $opponentMoves,
        ];
    }
}
