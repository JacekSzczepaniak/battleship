<?php

namespace App\Domain\Expedition;

/**
 * Stały graf akwenów. Na razie jeden węzeł: Morze Rozbitków z katalogiem
 * wysp plastra A/B i motywem 20% / 1 / ×1, na którym gra działa jak przed
 * akwenami. Kolejne akweny i bramy dochodzą jako węzły i krawędzie (#107, #108).
 */
final class StaticSeaGraph implements SeaGraph
{
    /** @var list<Sea> */
    private array $seas;

    /** @var list<SeaPassage> */
    private array $passages;

    /**
     * @param list<Sea>|null   $seas     null = akweny gry; inne listy służą testom
     * @param list<SeaPassage> $passages
     */
    public function __construct(StaticIslandCatalog $shipwreckIslands, ?array $seas = null, array $passages = [])
    {
        $this->seas = $seas ?? [
            new Sea(Sea::START, 'Morze Rozbitków', new SeaTheme(stormChance: 20, visibility: 1, depositMultiplier: 1), $shipwreckIslands),
        ];
        $this->passages = $passages;

        if ([] === $this->seas) {
            throw new \InvalidArgumentException('Sea graph needs at least one sea');
        }
        $seaIds = array_map(static fn (Sea $sea) => $sea->id, $this->seas);
        if (count($seaIds) !== count(array_unique($seaIds))) {
            throw new \InvalidArgumentException('Sea ids must be unique');
        }
        // wyspy szuka się po id w całym świecie (bandery, magazyny, budynki), więc id nie mogą się powtarzać między akwenami
        $islandIds = array_merge(...array_map(static fn (Sea $sea) => array_map(static fn (Island $i) => $i->id, $sea->islands->all()), $this->seas));
        if (count($islandIds) !== count(array_unique($islandIds))) {
            throw new \InvalidArgumentException('Island ids must be unique across seas');
        }
        foreach ($this->passages as $passage) {
            if (!in_array($passage->from, $seaIds, true) || !in_array($passage->to, $seaIds, true)) {
                throw new \InvalidArgumentException('Sea passage connects an unknown sea');
            }
        }
    }

    public function seas(): array
    {
        return $this->seas;
    }

    public function sea(string $id): ?Sea
    {
        foreach ($this->seas as $sea) {
            if ($sea->id === $id) {
                return $sea;
            }
        }

        return null;
    }

    public function start(): Sea
    {
        return $this->seas[0];
    }

    public function passagesFrom(string $seaId): array
    {
        return array_values(array_filter($this->passages, static fn (SeaPassage $p) => $p->from === $seaId));
    }
}
