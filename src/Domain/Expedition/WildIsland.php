<?php

namespace App\Domain\Expedition;

/**
 * Dzika wyspa (#144, Epic J D6): jedna na akwen, bez areny, bandery
 * i magazynu, dlatego nie należy do trasy wysp katalogu
 * (IslandCatalog::all()). Kapitan wyrusza w głąb lądu na trzy etapy;
 * przed każdym kolejnym wybiera „głębiej” albo „wracaj” (press-your-luck,
 * filar VII). Ryzyko etapu rośnie, nagrody też, a po etapie 3 jeden rzut
 * na skarb. Pozycję na mapie losuje seed akwenu osobnym rzutem, po porcie
 * handlowym, więc rozkład wysp, bram i portu się nie zmienia.
 */
final class WildIsland
{
    public const STAGES = 3;

    /** Szansa porażki na etapie (w %), deterministycznie z seeda i tiku. */
    private const RISKS = [1 => 20, 2 => 35, 3 => 50];

    /** Po zakończonej ekspedycji ta sama wyspa czeka tyle tików na następną. */
    public const REST_TICKS = 15;

    /** Złoto zamiast skarbu, który kapitan już ma (mapa, legenda, brak artefaktów do wylosowania). */
    public const TREASURE_FALLBACK_GOLD = 20;

    /**
     * @param list<Resources>                        $stageRewards nagroda każdego etapu (sumują się)
     * @param list<array{0: InlandTreasure, 1: int}> $treasures    skarby etapu 3 z szansą w %, rzut przechodzi je po kolei
     * @param list<string>                           $stories      krótki tekst każdego etapu (filar VII)
     */
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly Rank $requiredRank,
        public readonly array $stageRewards,
        public readonly array $treasures,
        public readonly array $stories,
    ) {
        if ('' === $id || '' === $name) {
            throw new \InvalidArgumentException('Wild island needs an id and a name');
        }
        if (self::STAGES !== count($stageRewards) || self::STAGES !== count($stories)) {
            throw new \InvalidArgumentException('Wild island needs a reward and a story for each stage');
        }
        if (array_sum(array_map(static fn (array $t) => $t[1], $treasures)) > 100) {
            throw new \InvalidArgumentException('Treasure chances exceed 100%');
        }
    }

    /** Szansa porażki na etapie 1–3 (w %). */
    public static function risk(int $stage): int
    {
        return self::RISKS[$stage] ?? throw new \InvalidArgumentException('Unknown inland stage');
    }

    public function reward(int $stage): Resources
    {
        return $this->stageRewards[$stage - 1] ?? throw new \InvalidArgumentException('Unknown inland stage');
    }

    /** Skarb z rzutu 0–99: pierwszy, w którego przedziale szans wypadł rzut; null = bez skarbu. */
    public function treasureFor(int $roll): ?InlandTreasure
    {
        $upTo = 0;
        foreach ($this->treasures as [$treasure, $chance]) {
            $upTo += $chance;
            if ($roll < $upTo) {
                return $treasure;
            }
        }

        return null;
    }

    public function isAccessibleFor(Rank $rank): bool
    {
        return $rank->atLeast($this->requiredRank);
    }
}
