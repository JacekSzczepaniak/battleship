<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Game\Coordinate;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;

/**
 * Klasyczna flota 10x10: 1x4, 2x3, 3x2, 4x1.
 * Brak styku (również po skosie), w granicach planszy.
 * WYMAGANE przez testy:
 *  - traf w (0,0) = HIT (część większego statku),
 *  - traf w (0,6) = SUNK (jedynka).
 */
final class FleetFactory
{
    /** @return Ship[] */
    public static function classic10x10(): array
    {
        return [
            // 4-masztowiec (H) zaczyna w (0,0) -> strzał (0,0) = HIT
            new Ship(new Coordinate(0, 0), Orientation::H, 4),

            // 3-masztowce
            new Ship(new Coordinate(0, 2), Orientation::H, 3),
            new Ship(new Coordinate(6, 0), Orientation::V, 3),

            // 2-masztowce
            new Ship(new Coordinate(5, 4), Orientation::H, 2),
            new Ship(new Coordinate(9, 0), Orientation::V, 2),
            new Ship(new Coordinate(3, 6), Orientation::V, 2),

            // 1-masztowce (w tym WYMAGANA jedynka w (0,6))
            new Ship(new Coordinate(0, 6), Orientation::H, 1), // -> strzał (0,6) = SUNK
            new Ship(new Coordinate(1, 8), Orientation::H, 1),
            new Ship(new Coordinate(5, 9), Orientation::H, 1),
            new Ship(new Coordinate(8, 8), Orientation::H, 1),
        ];
    }

    /**
     * Wersja do payloadu API (POST /api/games/{id}/fleet).
     * Zwraca samą listę statków (test dokleja klucz 'ships' przy json_encode()).
     *
     * @return array<int, array{x:int,y:int,o:string,l:int}>
     */
    public static function classic10x10Array(): array
    {
        $ships = self::classic10x10();

        return array_map(
            fn (Ship $s) => [
                'x' => $s->start->x,
                'y' => $s->start->y,
                'o' => $s->orientation->value, // 'h' / 'v'
                'l' => $s->length,
            ],
            $ships
        );
    }
}
