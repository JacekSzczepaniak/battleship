<?php

namespace App\Domain\Expedition;

/**
 * Port handlowy (#140, Epic J D1): jedna neutralna wyspa na akwenie. Nie ma
 * na niej bitwy, bandery ani złoża, a kontrataki jej nie dotyczą, dlatego
 * nie należy do trasy wysp katalogu (IslandCatalog::all()). Ma magazyn jak
 * każda wyspa i rynek ze stałym kursem akwenu. Pozycję na mapie losuje seed
 * akwenu osobnym rzutem, więc rozkład wysp trasy się nie zmienia.
 */
final class TradePort
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly MarketRate $rate,
    ) {
        if ('' === $id || '' === $name) {
            throw new \InvalidArgumentException('Trade port needs an id and a name');
        }
    }
}
