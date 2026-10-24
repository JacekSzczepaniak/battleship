<?php

namespace App\Domain\Expedition;

/**
 * Stały graf akwenów: Morze Rozbitków (katalog wysp plastra A/B, motyw
 * 20% / 1 / ×1, na którym gra działa jak przed akwenami) i za strzeżoną
 * cieśniną Ocean Sztormów (40% / 1 / ×2, #107), a za otwartym oceanem na
 * jego wschodniej krawędzi Morze Mgieł (10% / 0 / ×2, #108). Każdy akwen
 * ma neutralny port handlowy z katalogu swoich wysp (#140).
 */
final class StaticSeaGraph implements SeaGraph
{
    public const STORM_OCEAN = 'ocean-sztormow';
    public const GUARDED_STRAIT = 'strzezona-ciesnina';
    public const MIST_SEA = 'morze-mgiel';
    public const OPEN_OCEAN = 'otwarty-ocean';

    /** @var list<Sea> */
    private array $seas;

    /** @var list<SeaPassage> */
    private array $passages;

    /**
     * @param list<Sea>|null        $seas     null = akweny gry; inne listy służą testom
     * @param list<SeaPassage>|null $passages null = bramy gry (tylko razem z akwenami gry)
     */
    public function __construct(StaticIslandCatalog $shipwreckIslands, ?array $seas = null, ?array $passages = null)
    {
        if (null === $seas) {
            $stormIslands = new StormOceanIslandCatalog();
            $mistIslands = new MistSeaIslandCatalog();
            $this->seas = [
                new Sea(Sea::START, 'Morze Rozbitków', new SeaTheme(stormChance: 20, visibility: 1, depositMultiplier: 1), $shipwreckIslands, tradePort: $shipwreckIslands->tradePort()),
                new Sea(self::STORM_OCEAN, 'Ocean Sztormów', new SeaTheme(stormChance: 40, visibility: 1, depositMultiplier: 2), $stormIslands, [ShipVariant::KuterTorpedowy, ShipVariant::CiezkiNiszczyciel], $stormIslands->tradePort()),
                new Sea(self::MIST_SEA, 'Morze Mgieł', new SeaTheme(stormChance: 10, visibility: 0, depositMultiplier: 2), $mistIslands, [ShipVariant::LotniskowiecEskortowy], $mistIslands->tradePort()),
            ];
            $strait = self::guardedStrait();
            // otwarty ocean: bez bitwy, otwiera go technologia (#108)
            $ocean = new SeaGate(self::OPEN_OCEAN, 'Otwarty ocean', new TechnologyCondition(Technology::NawigacjaOceaniczna));
            $this->passages = $passages ?? [
                new SeaPassage(Sea::START, self::STORM_OCEAN, $strait, SeaEdge::East),
                new SeaPassage(self::STORM_OCEAN, Sea::START, $strait, SeaEdge::West),
                new SeaPassage(self::STORM_OCEAN, self::MIST_SEA, $ocean, SeaEdge::East),
                new SeaPassage(self::MIST_SEA, self::STORM_OCEAN, $ocean, SeaEdge::West),
            ];
        } else {
            $this->seas = $seas;
            $this->passages = $passages ?? [];
        }

        if ([] === $this->seas) {
            throw new \InvalidArgumentException('Sea graph needs at least one sea');
        }
        $seaIds = array_map(static fn (Sea $sea) => $sea->id, $this->seas);
        if (count($seaIds) !== count(array_unique($seaIds))) {
            throw new \InvalidArgumentException('Sea ids must be unique');
        }
        // wyspy szuka się po id w całym świecie (bandery, magazyny, budynki), więc id nie mogą się powtarzać między akwenami;
        // magazyn ma też port handlowy (#140), więc jego id liczy się razem z wyspami
        $islandIds = array_merge(...array_map(static fn (Sea $sea) => [
            ...array_map(static fn (Island $i) => $i->id, $sea->islands->all()),
            ...(null !== $sea->tradePort ? [$sea->tradePort->id] : []),
        ], $this->seas));
        if (count($islandIds) !== count(array_unique($islandIds))) {
            throw new \InvalidArgumentException('Island ids must be unique across seas');
        }
        $gates = [];
        foreach ($this->passages as $passage) {
            if (!in_array($passage->from, $seaIds, true) || !in_array($passage->to, $seaIds, true)) {
                throw new \InvalidArgumentException('Sea passage connects an unknown sea');
            }
            $key = "{$passage->from}:{$passage->gate->id}";
            if (isset($gates[$key])) {
                throw new \InvalidArgumentException('Sea gate listed twice on one sea');
            }
            $gates[$key] = $passage;
        }
        // brama działa w obie strony: flota ląduje na sektorze bramy w akwenie docelowym
        foreach ($this->passages as $passage) {
            $back = $gates["{$passage->to}:{$passage->gate->id}"] ?? null;
            if (null === $back || $back->to !== $passage->from || $back->gate !== $passage->gate) {
                throw new \InvalidArgumentException('Sea gate needs a passage back');
            }
        }
    }

    /**
     * Strzeżona cieśnina: od rangi porucznik, strażnik z flotą silniejszą niż
     * garnizony Akwenu 1 (17 pól na planszy 12×12). Łup trafia do ładowni.
     * Kuter torpedowy i ciężki niszczyciel to warianty Oceanu ze zdobyczy (#121).
     */
    private static function guardedStrait(): SeaGate
    {
        return new SeaGate(self::GUARDED_STRAIT, 'Strzeżona cieśnina', new GuardianCondition(new Guardian(
            name: 'Strażnik cieśniny',
            description: 'Eskadra trzyma wschodnie wyjście z Morza Rozbitków. Dopóki pływa, nikt nie wyjdzie na Ocean Sztormów.',
            requiredRank: Rank::Porucznik,
            mode: 'fun',
            fleet: [
                ShipType::Lotniskowiec,
                ShipType::Niszczyciel, ShipType::Niszczyciel,
                ShipType::Kuter, ShipType::Kuter,
                ShipType::Tratwa, ShipType::Tratwa, ShipType::Tratwa,
            ],
            xpWin: 200,
            xpLoss: 50,
            lootWin: new Resources(wood: 40, steel: 40, gold: 20),
            lootLoss: new Resources(wood: 10),
            boardWidth: 12,
            boardHeight: 12,
            // strażnik wejścia na Ocean Sztormów pływa z wariantami Oceanu (#121)
            variants: [ShipVariant::KuterTorpedowy, ShipVariant::CiezkiNiszczyciel],
        )));
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

    public function passage(string $fromSeaId, string $gateId): ?SeaPassage
    {
        foreach ($this->passagesFrom($fromSeaId) as $passage) {
            if ($passage->gate->id === $gateId) {
                return $passage;
            }
        }

        return null;
    }

    public function tradePort(string $portId): ?TradePort
    {
        foreach ($this->seas as $sea) {
            if (null !== $sea->tradePort && $sea->tradePort->id === $portId) {
                return $sea->tradePort;
            }
        }

        return null;
    }

    public function gate(string $gateId): ?SeaGate
    {
        foreach ($this->passages as $passage) {
            if ($passage->gate->id === $gateId) {
                return $passage->gate;
            }
        }

        return null;
    }
}
