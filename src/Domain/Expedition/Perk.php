<?php

namespace App\Domain\Expedition;

/**
 * Katalog perków specjalizacji (D4 Epica K): jeden perk na poziom ścieżki.
 * Perk działa od swojego poziomu na stałe (CaptainProfile::hasPerk()).
 * Tu jest tylko katalog; efekty wpinają #161 (bojowe) i #162 (ekonomiczne).
 * Perki ma wyłącznie kapitan gracza, wróg ich nie dostaje (D6).
 */
enum Perk: string
{
    /** Bojowa 1: remont po wygranej bitwie −20%. */
    case RepairDiscount = 'repair-discount';

    /** Bojowa 2: próg abordażu −1 (lista modyfikatorów z #118). */
    case BoardingEdge = 'boarding-edge';

    /** Bojowa 3: +1 użycie sonaru w bitwie. */
    case ExtraSonar = 'extra-sonar';

    /** Bojowa 4: łup z bitew +20%. */
    case LootBonus = 'loot-bonus';

    /** Bojowa 5: +1⚡ w pierwszej turze bitwy. */
    case FirstTurnEnergy = 'first-turn-energy';

    /** Ekonomiczna 1: ładownia +10%. */
    case LargerHold = 'larger-hold';

    /** Ekonomiczna 2: ceny rynku −5%. */
    case MarketDiscount = 'market-discount';

    /** Ekonomiczna 3: budowa statków −10%. */
    case CheaperShipbuilding = 'cheaper-shipbuilding';

    /** Ekonomiczna 4: magazyn złóż +5 tików produkcji. */
    case DepositStorage = 'deposit-storage';

    /** Ekonomiczna 5: ceny rynku −10% łącznie, handlarz na morzu bez dopłaty. */
    case MasterTrader = 'master-trader';

    public function track(): SpecializationTrack
    {
        return match ($this) {
            self::RepairDiscount, self::BoardingEdge, self::ExtraSonar, self::LootBonus, self::FirstTurnEnergy => SpecializationTrack::Combat,
            default => SpecializationTrack::Economy,
        };
    }

    /** Poziom ścieżki, od którego perk działa. */
    public function level(): int
    {
        return match ($this) {
            self::RepairDiscount, self::LargerHold => 1,
            self::BoardingEdge, self::MarketDiscount => 2,
            self::ExtraSonar, self::CheaperShipbuilding => 3,
            self::LootBonus, self::DepositStorage => 4,
            self::FirstTurnEnergy, self::MasterTrader => 5,
        };
    }
}
