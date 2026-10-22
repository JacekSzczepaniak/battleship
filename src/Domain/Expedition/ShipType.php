<?php

namespace App\Domain\Expedition;

/**
 * Typ statku floty wyprawy. Długość wiąże typ z bitwą (agregat Game zna tylko
 * długości), a koszty i bramki (ranga, poziom stoczni) napędzają ekonomię.
 * Tratwa jest zawsze darmowa — bezpiecznik anty-softlock: rozbitek zawsze
 * może wypłynąć (docs/GAME_DESIGN.md, filar 5).
 */
enum ShipType: string
{
    case Tratwa = 'tratwa';
    case Kuter = 'kuter';
    case Niszczyciel = 'niszczyciel';
    case Lotniskowiec = 'lotniskowiec';

    /** Długość statku na planszy bitwy. */
    public function length(): int
    {
        return match ($this) {
            self::Tratwa => 1,
            self::Kuter => 2,
            self::Niszczyciel => 3,
            self::Lotniskowiec => 4,
        };
    }

    /**
     * Pojemność ładowni (suma surowców). Okręty wojenne wożą mało — duża
     * ładownia to rola transportowca (#47).
     */
    public function cargoCapacity(): int
    {
        return match ($this) {
            self::Tratwa => 5,
            self::Kuter => 15,
            self::Niszczyciel => 20,
            self::Lotniskowiec => 30,
        };
    }

    /** Koszt budowy: kadłub z drewna, większe jednostki także stal na uzbrojenie. */
    public function buildCost(): Resources
    {
        return match ($this) {
            self::Tratwa => Resources::none(),
            self::Kuter => new Resources(wood: 20),
            self::Niszczyciel => new Resources(wood: 30, steel: 20),
            self::Lotniskowiec => new Resources(wood: 60, steel: 60),
        };
    }

    /** Koszt remontu uszkodzonego statku. */
    public function repairCost(): Resources
    {
        return match ($this) {
            self::Tratwa => Resources::none(),
            self::Kuter => new Resources(wood: 8),
            self::Niszczyciel => new Resources(wood: 10, steel: 10),
            self::Lotniskowiec => new Resources(wood: 20, steel: 25),
        };
    }

    /** Minimalna ranga kapitana pozwalająca budować ten typ. */
    public function requiredRank(): Rank
    {
        return match ($this) {
            self::Tratwa, self::Kuter => Rank::Rozbitek,
            self::Niszczyciel => Rank::Marynarz,
            self::Lotniskowiec => Rank::Kapitan,
        };
    }

    /** Minimalny poziom stoczni potrzebny do budowy i remontu. */
    public function requiredShipyardLevel(): int
    {
        return match ($this) {
            self::Tratwa, self::Kuter => 1,
            self::Niszczyciel => 2,
            self::Lotniskowiec => 3,
        };
    }
}
