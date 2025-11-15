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
        $loss = false; // w obecnym modelu nie śledzimy przegranej (brak planszy gracza)

        $opponentMoves = [];

        // Mock przeciwnika: 1 strzał synchronicznie, tylko gdy gra nie skończona
        if (!$finished) {
            $boardSize = $game->ruleset()->boardSize();
            // Zbiór pól już ostrzelanych przez gracza (aby wygladało sensowniej)
            $shotMap = [];
            foreach ($game->shots() as $s) {
                $shotMap[$s['x'] . ':' . $s['y']] = true;
            }
            $mx = 0;
            $my = 0;
            $found = false;
            for ($yy = 0; $yy < $boardSize->height; ++$yy) {
                for ($xx = 0; $xx < $boardSize->width; ++$xx) {
                    if (!isset($shotMap[$xx . ':' . $yy])) {
                        $mx = $xx;
                        $my = $yy;
                        $found = true;
                        break;
                    }
                }
                if ($found) {
                    break;
                }
            }
            // Brak drugiej planszy, więc wynik ruchu przeciwnika traktujemy neutralnie jako "miss"
            $opponentMoves[] = ['x' => $mx, 'y' => $my, 'result' => 'miss'];
        }

        // Po ruchu przeciwnika tura wraca do gracza
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
