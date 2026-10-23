<?php

namespace App\Domain\Expedition;

/**
 * Brama między dwoma akwenami (Epic I, D3). Jedna brama to dwie krawędzie
 * grafu (SeaPassage), po jednej w każdą stronę, ze wspólnym id — otwarcie
 * działa w obie strony. Typ bramy wyznacza jej warunek.
 */
final class SeaGate
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly GateCondition $condition,
    ) {
        if ('' === $id) {
            throw new \InvalidArgumentException('Sea gate needs an id');
        }
    }

    /** Strażnik bramy; null = brama bez bitwy (np. otwarty ocean, #108). */
    public function guardian(): ?Guardian
    {
        return $this->condition instanceof GuardianCondition ? $this->condition->guardian : null;
    }
}
