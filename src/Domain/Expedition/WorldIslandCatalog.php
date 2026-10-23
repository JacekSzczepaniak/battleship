<?php

namespace App\Domain\Expedition;

/**
 * Wszystkie wyspy świata ze wszystkich akwenów. Bandery, magazyny i budynki
 * są przypisane do wysp, więc wyspę szuka się po id niezależnie od akwenu;
 * to, czy kapitan na niej stoi, sprawdza mapa bieżącego akwenu.
 */
final class WorldIslandCatalog implements IslandCatalog
{
    public function __construct(private SeaGraph $seas)
    {
    }

    public function all(): array
    {
        return array_merge(...array_map(static fn (Sea $sea) => $sea->islands->all(), $this->seas->seas()));
    }

    public function byId(string $id): ?Island
    {
        foreach ($this->seas->seas() as $sea) {
            $island = $sea->islands->byId($id);
            if (null !== $island) {
                return $island;
            }
        }

        return null;
    }
}
