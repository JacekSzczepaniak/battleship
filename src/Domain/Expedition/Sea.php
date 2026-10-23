<?php

namespace App\Domain\Expedition;

/**
 * Akwen: węzeł grafu świata (Epic I). Każdy akwen ma własną mapę 12×12,
 * ręcznie projektowany katalog wysp i motyw. Pozycje wysp losuje seed akwenu,
 * wyprowadzony z seeda świata kapitana.
 */
final class Sea
{
    /** Akwen 1 — start wyprawy i dom profili sprzed akwenów. */
    public const START = 'morze-rozbitkow';

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly SeaTheme $theme,
        public readonly IslandCatalog $islands,
    ) {
        if ('' === $id || [] === $islands->all()) {
            throw new \InvalidArgumentException('Sea needs an id and islands');
        }
    }

    /**
     * Seed akwenu z seeda świata. Morze Rozbitków bierze seed świata bez
     * zmian, żeby stare profile zachowały rozkład wysp pod zapisaną pozycją
     * i odkryciami; pozostałe akweny dostają seed z id akwenu.
     */
    public function seed(int $worldSeed): int
    {
        return self::START === $this->id ? $worldSeed : crc32("{$worldSeed}:{$this->id}") & 0x7FFFFFFF;
    }

    /**
     * Mapa akwenu dla danego seeda świata, z sektorami bram wychodzących
     * z akwenu (przejścia z innych akwenów są pomijane).
     *
     * @param list<SeaPassage> $passages
     */
    public function map(int $worldSeed, array $passages = []): WorldMap
    {
        $islands = $this->islands->all();
        $gates = [];
        foreach ($passages as $passage) {
            if ($passage->from === $this->id) {
                $gates[$passage->gate->id] = $passage->edge;
            }
        }

        return WorldMap::generate(
            $this->seed($worldSeed),
            array_map(static fn (Island $island) => $island->id, $islands),
            $islands,
            $this->theme,
            $this->id,
            $gates,
        );
    }
}
