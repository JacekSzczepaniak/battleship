<?php

namespace App\Domain\Expedition;

/**
 * Spotkania na morzu (#141, D3 Epica J): szansa, podział handlarz/pirat
 * i floty, okupy oraz ceny dla każdego akwenu. Liczby zatwierdził Jacek
 * 24.10.2026. Akwen spoza tabeli dostaje stawki Morza Rozbitków.
 */
final class EncounterCatalog
{
    /** Szansa spotkania przy rejsie (w %), z seeda i tiku jak sztorm. */
    public const CHANCE = 8;

    /** Udział handlarzy wśród spotkań (w %); reszta to piraci. */
    public const MERCHANT_SHARE = 60;

    /** Ucieczka przed piratem: tyle tików i procent każdego surowca w ładowni. */
    public const FLEE_TICKS = 1;
    public const FLEE_CARGO_PERCENT = 20;

    /** Handel u handlarza: ceny o tyle procent gorsze niż w porcie akwenu (#140), zlecenie kosztuje tik. */
    public const MERCHANT_WORSE_PERCENT = 25;
    public const TRADE_TICKS = 1;

    /** Okup w złocie z ładowni. */
    public static function ransom(string $seaId): int
    {
        return match ($seaId) {
            StaticSeaGraph::STORM_OCEAN => 25,
            // Wody Czarnej Perły (#143) mają spotkania jak Morze Mgieł
            StaticSeaGraph::MIST_SEA, StaticSeaGraph::BLACK_PEARL => 35,
            default => 10,
        };
    }

    /** Flota spotkania na akwenie: pirat do walki albo handlarz z eskortą do napadu. */
    public static function fleet(EncounterType $type, string $seaId): EncounterFleet
    {
        return match ($type) {
            EncounterType::Pirate => self::pirate($seaId),
            EncounterType::Merchant => self::merchant($seaId),
        };
    }

    private static function pirate(string $seaId): EncounterFleet
    {
        return match ($seaId) {
            StaticSeaGraph::STORM_OCEAN => new EncounterFleet(
                name: 'Piraci Oceanu',
                mode: 'fun',
                fleet: [ShipType::Lotniskowiec, ShipType::Niszczyciel, ShipType::Kuter, ShipType::Kuter, ShipType::Tratwa],
                xpWin: 150,
                xpLoss: 37,
                lootWin: new Resources(steel: 20, gold: 25),
                boardWidth: 10,
                boardHeight: 10,
            ),
            // Wody Czarnej Perły (#143) mają spotkania jak Morze Mgieł
            StaticSeaGraph::MIST_SEA, StaticSeaGraph::BLACK_PEARL => new EncounterFleet(
                name: 'Piraci z mgły',
                mode: 'fun',
                fleet: [ShipType::Lotniskowiec, ShipType::Niszczyciel, ShipType::Niszczyciel, ShipType::Kuter, ShipType::Kuter, ShipType::Tratwa],
                xpWin: 230,
                xpLoss: 57,
                lootWin: new Resources(steel: 25, gold: 35),
                boardWidth: 11,
                boardHeight: 11,
            ),
            default => new EncounterFleet(
                name: 'Piraci',
                mode: 'fun',
                fleet: [ShipType::Niszczyciel, ShipType::Kuter, ShipType::Kuter, ShipType::Tratwa],
                xpWin: 60,
                xpLoss: 15,
                lootWin: new Resources(steel: 10, gold: 15),
                boardWidth: 8,
                boardHeight: 8,
            ),
        };
    }

    private static function merchant(string $seaId): EncounterFleet
    {
        return match ($seaId) {
            StaticSeaGraph::STORM_OCEAN => new EncounterFleet(
                name: 'Handlarz z eskortą',
                mode: 'fun',
                fleet: [ShipType::Transportowiec, ShipType::Niszczyciel, ShipType::Kuter],
                xpWin: 120,
                xpLoss: 30,
                lootWin: new Resources(wood: 35, steel: 35, gold: 20),
                boardWidth: 10,
                boardHeight: 10,
            ),
            // Wody Czarnej Perły (#143) mają spotkania jak Morze Mgieł
            StaticSeaGraph::MIST_SEA, StaticSeaGraph::BLACK_PEARL => new EncounterFleet(
                name: 'Handlarz z eskortą',
                mode: 'fun',
                fleet: [ShipType::Transportowiec, ShipType::Niszczyciel, ShipType::Kuter, ShipType::Kuter],
                xpWin: 200,
                xpLoss: 50,
                lootWin: new Resources(wood: 45, steel: 35, gold: 30),
                boardWidth: 11,
                boardHeight: 11,
            ),
            default => new EncounterFleet(
                name: 'Handlarz',
                mode: 'fun',
                fleet: [ShipType::Transportowiec, ShipType::Kuter, ShipType::Tratwa],
                xpWin: 40,
                xpLoss: 10,
                lootWin: new Resources(wood: 30, steel: 15, gold: 10),
                boardWidth: 8,
                boardHeight: 8,
            ),
        };
    }
}
