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

    /**
     * @param list<ShipVariant> $enemyVariants warianty ze zdobyczy, które wróg ma w składzie na wyspach akwenu (#121)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly SeaTheme $theme,
        public readonly IslandCatalog $islands,
        public readonly array $enemyVariants = [],
    ) {
        if ('' === $id || [] === $islands->all()) {
            throw new \InvalidArgumentException('Sea needs an id and islands');
        }
    }

    /**
     * Wariant ze zdobyczy w składzie wroga na wyspie (#121): jeden z wariantów
     * akwenu, stały dla wyspy w danym świecie; null = akwen bez takich wariantów.
     */
    public function enemyVariantFor(int $worldSeed, string $islandId): ?ShipVariant
    {
        if ([] === $this->enemyVariants) {
            return null;
        }

        return $this->enemyVariants[(crc32("{$worldSeed}:{$islandId}:enemy-variant") & 0x7FFFFFFF) % count($this->enemyVariants)];
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
