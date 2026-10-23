<?php

namespace App\Domain\Expedition;

/**
 * Krawędź grafu akwenów: przejście z jednego akwenu do drugiego przez bramę.
 * Sektor bramy leży na krawędzi mapy akwenu $from ($edge); flota przepływa
 * na sektor tej samej bramy na mapie akwenu $to (krawędź przejścia powrotnego).
 */
final class SeaPassage
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
        public readonly SeaGate $gate,
        public readonly SeaEdge $edge,
    ) {
        if ($from === $to) {
            throw new \InvalidArgumentException('Sea passage must lead to another sea');
        }
    }
}
