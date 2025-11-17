<?php

namespace App\Application\Game;

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

        $out = $game->fireShot(new Coordinate($x, $y));

        // Wyliczenia po ruchu gracza
        $finished = $game->isFinished();
        $win = 'won' === $game->status()->value;
        $loss = 'lost' === $game->status()->value;

        $opponentMoves = [];

        // Mock przeciwnika (AI v1): 1 strzał synchronicznie, tylko gdy gra nie skończona
        if (!$finished) {
            // wybór celu według logiki AI (hunt/target)
            $target = $game->chooseOpponentTarget();
            $oppResult = $game->fireOpponentShot($target);
            $opponentMoves[] = ['x' => $target->x, 'y' => $target->y, 'result' => $oppResult];

            // Re-ewaluacja zakończenia po ruchu przeciwnika
            $finished = $finished || 'lost' === $game->status()->value;
            $win = 'won' === $game->status()->value;
            $loss = 'lost' === $game->status()->value;
        }

        // Po ruchu przeciwnika tura wraca do gracza (o ile gra nie skończona)
        $turn = 'player';

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
