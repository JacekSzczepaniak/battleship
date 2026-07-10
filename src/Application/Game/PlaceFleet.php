<?php

namespace App\Application\Game;

use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Shared\GameId;

final class PlaceFleet
{
    public function __construct(private GameRepository $repo)
    {
    }

    /**
     * @param array<int,array{x:int,y:int,o:string,l:int}> $shipsSpec
     */
    public function handle(string $gameId, array $shipsSpec): void
    {
        $game = $this->repo->get(new GameId($gameId));
        if (!$game) {
            throw new \RuntimeException('Game not found');
        }

        $ships = [];
        foreach ($shipsSpec as $s) {
            $ships[] = new Ship(
                new Coordinate((int) $s['x'], (int) $s['y']),
                Orientation::from(strtoupper((string) $s['o'])),
                (int) $s['l']
            );
        }

        $game->placeFleet($ships);

        // Po rozstawieniu floty gracza – wygeneruj flotę przeciwnika
        $env = (string) ($_ENV['APP_ENV'] ?? $_SERVER['APP_ENV'] ?? 'dev');
        $boardSize = $game->ruleset()->boardSize();
        $opponent = [];
        if ($env === 'test') {
            // deterministyczny układ zgodny z klasycznym 10x10 (jak FleetFactory::classic10x10Array)
            $opponent = [
                ['x'=>0,'y'=>0,'o'=>'H','l'=>4],
                ['x'=>0,'y'=>2,'o'=>'H','l'=>3],
                ['x'=>6,'y'=>0,'o'=>'V','l'=>3],
                ['x'=>5,'y'=>4,'o'=>'H','l'=>2],
                ['x'=>9,'y'=>0,'o'=>'V','l'=>2],
                ['x'=>3,'y'=>6,'o'=>'V','l'=>2],
                ['x'=>0,'y'=>6,'o'=>'H','l'=>1],
                ['x'=>1,'y'=>8,'o'=>'H','l'=>1],
                ['x'=>5,'y'=>9,'o'=>'H','l'=>1],
                ['x'=>8,'y'=>8,'o'=>'H','l'=>1],
            ];
            $oppShips = [];
            foreach ($opponent as $s) {
                $oppShips[] = new Ship(new Coordinate($s['x'], $s['y']), Orientation::from($s['o']), $s['l']);
            }
            $game->placeOpponentFleet($oppShips);
        } else {
            // losowy układ klasycznej floty dla rozmiaru planszy (domyślnie 10x10)
            $oppShips = $this->generateRandomClassicFleet($boardSize->width, $boardSize->height);
            $game->placeOpponentFleet($oppShips);
        }

        $this->repo->save($game);
    }

    /**
     * Generuje klasyczną flotę (1x4, 2x3, 3x2, 4x1) losowo na planszy o rozmiarze w×h.
     * Stosuje proste wielokrotne próby aż do udanego rozstawienia.
     * @return Ship[]
     */
    private function generateRandomClassicFleet(int $w, int $h): array
    {
        $lengths = [4,3,3,2,2,2,1,1,1,1];
        $maxAttempts = 5000;
        $attempt = 0;
        while ($attempt++ < $maxAttempts) {
            $ships = [];
            try {
                foreach ($lengths as $l) {
                    $placed = false;
                    $innerAttempts = 0;
                    while (!$placed && $innerAttempts++ < 500) {
                        $o = (random_int(0,1) === 0) ? Orientation::H : Orientation::V;
                        if ($o === Orientation::H) {
                            $x = random_int(0, max(0, $w - $l));
                            $y = random_int(0, $h - 1);
                        } else {
                            $x = random_int(0, $w - 1);
                            $y = random_int(0, max(0, $h - $l));
                        }
                        $candidate = new Ship(new Coordinate($x, $y), $o, $l);
                        // Walidacja poprzez próbę położenia całości – użyj tymczasowej planszy
                        $tmpShips = $ships;
                        $tmpShips[] = $candidate;
                        // użytkuj domenową Board przez Game::placeOpponentFleet – tutaj tylko weryfikacja przez Board::placeMany
                        $board = new \App\Domain\Game\Board(new \App\Domain\Game\BoardSize($w, $h));
                        $board->placeMany($tmpShips);
                        $ships[] = $candidate;
                        $placed = true;
                    }
                    if (!$placed) {
                        throw new \RuntimeException('Failed to place ship');
                    }
                }
                return $ships;
            } catch (\Throwable) {
                // spróbuj od nowa cały układ
            }
        }
        // awaryjnie – wróć do deterministycznego klasyka
        return [
            new Ship(new Coordinate(0,0), Orientation::H, 4),
            new Ship(new Coordinate(0,2), Orientation::H, 3),
            new Ship(new Coordinate(6,0), Orientation::V, 3),
            new Ship(new Coordinate(5,4), Orientation::H, 2),
            new Ship(new Coordinate(9,0), Orientation::V, 2),
            new Ship(new Coordinate(3,6), Orientation::V, 2),
            new Ship(new Coordinate(0,6), Orientation::H, 1),
            new Ship(new Coordinate(1,8), Orientation::H, 1),
            new Ship(new Coordinate(5,9), Orientation::H, 1),
            new Ship(new Coordinate(8,8), Orientation::H, 1),
        ];
    }
}
