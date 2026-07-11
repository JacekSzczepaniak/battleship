<?php

namespace App\Domain\Game;

/**
 * Deterministyczny układ floty. Dla floty klasycznej zwraca stały układ
 * zgodny z Tests\Support\FleetFactory::classic10x10() (przewidywalne testy
 * funkcjonalne). Dla niestandardowego składu (skład wyprawy) pakuje statki
 * wierszami: najdłuższe pierwsze, odstęp 1 pola (statki nie mogą się stykać).
 * Używany w env testowym oraz jako awaryjny fallback generatora losowego.
 */
final class DeterministicFleetGenerator implements FleetGenerator
{
    public function generate(Ruleset $ruleset): array
    {
        $composition = $ruleset->allowedShips();

        if (FleetComposition::isClassic($composition)) {
            return $this->classicLayout();
        }

        return $this->rowPackedLayout($composition, $ruleset->boardSize());
    }

    /** @return Ship[] */
    private function classicLayout(): array
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

    /**
     * @param array<int,int> $composition
     *
     * @return Ship[]
     */
    private function rowPackedLayout(array $composition, BoardSize $size): array
    {
        krsort($composition);

        $ships = [];
        $x = 0;
        $y = 0;
        foreach ($composition as $length => $count) {
            for ($i = 0; $i < $count; ++$i) {
                if ($x + $length > $size->width) {
                    $x = 0;
                    $y += 2; // pusty wiersz odstępu — statki nie mogą się stykać
                }
                if ($y >= $size->height) {
                    throw new \DomainException('Fleet does not fit on board');
                }
                $ships[] = new Ship(new Coordinate($x, $y), Orientation::H, $length);
                $x += $length + 1;
            }
        }

        return $ships;
    }
}
