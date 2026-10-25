<?php

namespace App\Domain\Expedition;

/**
 * Katalog wysp Morza Mgieł (Akwen 3, #108): 5 wysp za otwartym oceanem,
 * z rangami komandor i admirał — końcówka gry. Łupy i XP wyższe niż na
 * Oceanie Sztormów; mnożnik ×2 z motywu akwenu dotyczy złóż, nie łupów.
 * Stocznia tylko na Kotwicowisku Mgieł, od razu na najwyższym poziomie.
 */
final class MistSeaIslandCatalog implements IslandCatalog
{
    /** Cena bazowa mapy Wód Czarnej Perły w złocie (#143, Epic J D5). */
    public const CHART_PRICE = 150;

    /** @var list<Island>|null */
    private ?array $islands = null;

    public function all(): array
    {
        return $this->islands ??= [
            new Island(
                id: 'kotwicowisko-mgiel',
                name: 'Kotwicowisko Mgieł',
                description: 'Kotwice leżą tu na dnie gęsto jak kamienie. Kto zdobędzie suche doki, zbuduje w nich wszystko, co pływa.',
                requiredRank: Rank::Komandor,
                mode: 'fun',
                xpWin: 220,
                xpLoss: 55,
                lootWin: new Resources(wood: 30, steel: 30, gold: 24),
                lootLoss: new Resources(wood: 20),
                shipyardLevel: 3,
                boardWidth: 12,
                boardHeight: 12,
            ),
            new Island(
                id: 'wyspa-szeptow',
                name: 'Wyspa Szeptów',
                description: 'We mgle słychać głosy, zanim widać brzeg. Pod szeptami kryje się żyła złota.',
                requiredRank: Rank::Komandor,
                mode: 'fun',
                xpWin: 240,
                xpLoss: 60,
                lootWin: new Resources(wood: 30, steel: 32, gold: 26),
                lootLoss: new Resources(wood: 22),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                depositYield: new Resources(gold: 2),
            ),
            new Island(
                id: 'mielizna-widm',
                name: 'Mielizna Widm',
                description: 'Na mieliźnie stoją wraki, które wyglądają jak żywe okręty. Między nimi rośnie las na budulec.',
                requiredRank: Rank::Komandor,
                mode: 'fun',
                xpWin: 240,
                xpLoss: 60,
                lootWin: new Resources(wood: 34, steel: 30, gold: 25),
                lootLoss: new Resources(wood: 22),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                depositYield: new Resources(wood: 4),
            ),
            new Island(
                id: 'archipelag-slepcow',
                name: 'Archipelag Ślepców',
                description: 'Wyspy tak gęsto otulone mgłą, że ich obrońcy strzelają na słuch. Trafiają zaskakująco często.',
                requiredRank: Rank::Admiral,
                mode: 'fun',
                xpWin: 290,
                xpLoss: 72,
                lootWin: new Resources(wood: 36, steel: 38, gold: 30),
                lootLoss: new Resources(wood: 25),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                depositYield: new Resources(steel: 3),
            ),
            new Island(
                id: 'cytadela-mgiel',
                name: 'Cytadela Mgieł',
                description: 'Twierdza, której nikt nie widział w całości. Jej skarbiec to największy łup na morzach.',
                requiredRank: Rank::Admiral,
                mode: 'fun',
                xpWin: 340,
                xpLoss: 85,
                lootWin: new Resources(wood: 42, steel: 44, gold: 38),
                lootLoss: new Resources(wood: 28),
                shipyardLevel: 0,
                boardWidth: 12,
                boardHeight: 12,
                // fort: banderę daje dopiero wygrane oblężenie (#120)
                stronghold: true,
            ),
        ];
    }

    /**
     * Port handlowy Morza Mgieł (#140): neutralna wyspa poza trasą, z rynkiem
     * po stałym kursie akwenu. Drewno droższe niż na pozostałych akwenach.
     * Jedyny rynek z mapą Wód Czarnej Perły (#143).
     */
    public function tradePort(): TradePort
    {
        return new TradePort(
            id: 'bazar-we-mgle',
            name: 'Bazar we Mgle',
            description: 'Handel we mgle idzie na słowo. Drewno trzeba wozić zza oceanu, więc jest tu w cenie złota.',
            rate: new MarketRate(woodPerGold: 3, steelPerGold: 3),
            chart: new SeaChartOffer(StaticSeaGraph::BLACK_PEARL, self::CHART_PRICE),
        );
    }

    /**
     * Dzika wyspa Morza Mgieł (#144): poza trasą, bez areny i bandery,
     * z ekspedycją w głąb lądu na trzy etapy.
     */
    public function wildIsland(): WildIsland
    {
        return new WildIsland(
            id: 'wyspa-zaginionych',
            name: 'Wyspa Zaginionych',
            description: 'Mgła rozstępuje się tu tylko na chwilę. Załogi, które zeszły na ląd, rzadko wracały, ale te, które wróciły, miały mapy.',
            requiredRank: Rank::Komandor,
            stageRewards: [
                new Resources(wood: 20),
                new Resources(wood: 16, steel: 16),
                new Resources(steel: 16, gold: 14),
            ],
            // jedyna dzika wyspa z mapą Wód Czarnej Perły (#143): brama „wody nieznane” leży na Mgłach
            treasures: [[InlandTreasure::Chart, 35], [InlandTreasure::Artifact, 30]],
            stories: [
                'Ślady butów we mgle prowadzą w głąb lądu. Przy ścieżce leżą porzucone skrzynie z zapasami.',
                'Ruiny osady kartografów. Na ścianach wydrapano linie wybrzeży, których nie ma na żadnej mapie.',
                'Wieża latarni w sercu wyspy. Na jej szczycie leży zwój w nieprzemakalnej tubie.',
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
