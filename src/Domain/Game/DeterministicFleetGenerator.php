<?php

namespace App\Domain\Game;

/**
 * Stały, poprawny układ klasycznej floty (1x4, 2x3, 3x2, 4x1) w obrębie 10x10.
 * Używany w env testowym (przewidywalne testy funkcjonalne — układ musi być
 * zgodny z Tests\Support\FleetFactory::classic10x10()) oraz jako awaryjny
 * fallback generatora losowego. Mieści się na każdej planszy >= 10x10.
 */
final class DeterministicFleetGenerator implements FleetGenerator
{
    public function generate(Ruleset $ruleset): array
    {
        return [
            new Ship(new Coordinate(0, 0), Orientation::H, 4),
            new Ship(new Coordinate(0, 2), Orientation::H, 3),
            new Ship(new Coordinate(6, 0), Orientation::V, 3),
            new Ship(new Coordinate(5, 4), Orientation::H, 2),
            new Ship(new Coordinate(9, 0), Orientation::V, 2),
            new Ship(new Coordinate(3, 6), Orientation::V, 2),
            new Ship(new Coordinate(0, 6), Orientation::H, 1),
            new Ship(new Coordinate(1, 8), Orientation::H, 1),
            new Ship(new Coordinate(5, 9), Orientation::H, 1),
            new Ship(new Coordinate(8, 8), Orientation::H, 1),
        ];
    }
}
