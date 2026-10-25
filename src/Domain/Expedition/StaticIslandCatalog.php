<?php

namespace App\Domain\Expedition;

/**
 * Stały katalog wysp plastra A/B: liniowa trasa 6 wysp, kolejne bramkowane
 * rangą; wczesne wyspy mają mniejsze plansze (krótsze polowanie małą flotą).
 * Stocznie tylko na dwóch wyspach (Zatoka — start, Sygnałowa — hub), złoża
 * na trzech innych: surowce trzeba dowieźć do stoczni (#48). Zatoka to
 * bezpieczna przystań — wyspa startowa, której kontrataki nie dotyczą (#77).
 * Docelowo (plaster C) katalog będzie generowany z seeda świata.
 */
final class StaticIslandCatalog implements IslandCatalog
{
    /** @var list<Island>|null */
    private ?array $islands = null;

    public function all(): array
    {
        return $this->islands ??= [
            new Island(
                id: 'zatoka-rozbitka',
                name: 'Zatoka Rozbitka',
                description: 'Spokojne wody, na których uczysz się fachu. Ktoś tu jednak też ocalał — i nie chce się dzielić zatoką.',
                requiredRank: Rank::Rozbitek,
                mode: 'classic',
                xpWin: 40,
                xpLoss: 10,
                lootWin: new Resources(wood: 15, steel: 5),
                lootLoss: new Resources(wood: 5),
                shipyardLevel: 1,
                boardWidth: 7,
                boardHeight: 7,
                safeHarbor: true,
            ),
            new Island(
                id: 'mielizny',
                name: 'Mielizny',
                description: 'Płytkie wody i wraki dawnych flot. Miejscowi znają każdą łachę piachu — Ty jeszcze nie.',
                requiredRank: Rank::Rozbitek,
                mode: 'classic',
                xpWin: 50,
                xpLoss: 12,
                lootWin: new Resources(wood: 18, steel: 7),
                lootLoss: new Resources(wood: 6),
                shipyardLevel: 0,
                boardWidth: 8,
                boardHeight: 8,
                depositYield: new Resources(wood: 3),
            ),
            new Island(
                id: 'wyspa-sygnalowa',
                name: 'Wyspa Sygnałowa',
                description: 'Stara stacja nasłuchowa wciąż działa. Tu po raz pierwszy usłyszysz ping sonaru — swój i cudzy.',
                requiredRank: Rank::Marynarz,
                mode: 'fun',
                xpWin: 70,
                xpLoss: 15,
                lootWin: new Resources(wood: 20, steel: 10, gold: 5),
                lootLoss: new Resources(wood: 8),
                shipyardLevel: 3,
                boardWidth: 9,
                boardHeight: 9,
            ),
            new Island(
                id: 'archipelag-mgiel',
                name: 'Archipelag Mgieł',
                description: 'Wyspy, które znikają z map. Walka w gęstej mgle wymaga zimnej krwi i pełnego arsenału.',
                requiredRank: Rank::Marynarz,
                mode: 'fun',
                xpWin: 80,
                xpLoss: 20,
                lootWin: new Resources(wood: 20, steel: 12, gold: 8),
                lootLoss: new Resources(wood: 10),
                shipyardLevel: 0,
                boardWidth: 10,
                boardHeight: 10,
                depositYield: new Resources(steel: 2),
            ),
            new Island(
                id: 'ciesnina-sztormow',
                name: 'Cieśnina Sztormów',
                description: 'Wąskie gardło szlaku handlowego. Kto trzyma cieśninę, trzyma całe morze — dlatego wszyscy o nią walczą.',
                requiredRank: Rank::Kapitan,
                mode: 'fun',
                xpWin: 100,
                xpLoss: 25,
                lootWin: new Resources(wood: 20, steel: 18, gold: 12),
                lootLoss: new Resources(wood: 12),
                shipyardLevel: 0,
                boardWidth: 10,
                boardHeight: 10,
                depositYield: new Resources(gold: 1),
            ),
            new Island(
                id: 'twierdza-admiralicji',
                name: 'Twierdza Admiralicji',
                description: 'Ostatni bastion starej floty. Pokonaj garnizon twierdzy, a nikt nie odmówi Ci szarży admiralskiej.',
                requiredRank: Rank::Kapitan,
                mode: 'fun',
                xpWin: 120,
                xpLoss: 30,
                lootWin: new Resources(wood: 20, steel: 22, gold: 18),
                lootLoss: new Resources(wood: 15),
                shipyardLevel: 0,
                boardWidth: 10,
                boardHeight: 10,
                // fort: banderę daje dopiero wygrane oblężenie (#120)
                stronghold: true,
            ),
        ];
    }

    /**
     * Port handlowy Morza Rozbitków (#140): neutralna wyspa poza trasą, z rynkiem
     * po stałym kursie akwenu.
     */
    public function tradePort(): TradePort
    {
        return new TradePort(
            id: 'targ-rozbitkow',
            name: 'Targ Rozbitków',
            description: 'Kupcy skupują tu wszystko, co morze wyrzuci na brzeg. Na targu nikt nie strzela: handel ma pierwszeństwo przed banderą.',
            rate: new MarketRate(woodPerGold: 5, steelPerGold: 3),
        );
    }

    /**
     * Dzika wyspa Morza Rozbitków (#144): poza trasą, bez areny i bandery,
     * z ekspedycją w głąb lądu na trzy etapy.
     */
    public function wildIsland(): WildIsland
    {
        return new WildIsland(
            id: 'wyspa-papug',
            name: 'Wyspa Papug',
            description: 'Papugi krzyczą z dżungli, zanim szalupa dotknie piasku. Rozbitkowie mówią, że ktoś przed nimi zakopał w głębi wyspy skrzynie.',
            requiredRank: Rank::Majtek,
            stageRewards: [
                new Resources(wood: 8),
                new Resources(wood: 6, steel: 6),
                new Resources(steel: 6, gold: 5),
            ],
            treasures: [[InlandTreasure::Artifact, 20]],
            stories: [
                'Plaża i skraj dżungli. Między palmami leży drewno z rozbitych łodzi.',
                'Ścieżka przez bagno prowadzi do opuszczonego obozu. W szałasach zostały narzędzia i sztaby stali.',
                'Na szczycie wzgórza papugi strzegą jaskini. W środku stoją stare skrzynie.',
            ],
        );
    }

    public function byId(string $id): ?Island
    {
        foreach ($this->all() as $island) {
            if ($island->id === $id) {
                return $island;
            }
        }

        return null;
    }
}
