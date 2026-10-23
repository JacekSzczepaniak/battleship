<?php

namespace App\Domain\Expedition;

/**
 * Krawędź grafu akwenów: przejście z jednego akwenu do drugiego. Warunki
 * przejścia (brama: strzeżona cieśnina, otwarty ocean) dochodzą razem
 * z kolejnymi akwenami (#107, #108).
 */
final class SeaPassage
{
    public function __construct(
        public readonly string $from,
        public readonly string $to,
    ) {
        if ($from === $to) {
            throw new \InvalidArgumentException('Sea passage must lead to another sea');
        }
    }
}
