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

    /** Koszt budowy w materiałach. */
    public function buildCost(): int
    {
        return match ($this) {
            self::Tratwa => 0,
            self::Kuter => 20,
            self::Niszczyciel => 50,
            self::Lotniskowiec => 120,
        };
    }

    /** Koszt remontu uszkodzonego statku w materiałach. */
    public function repairCost(): int
    {
        return match ($this) {
            self::Tratwa => 0,
            self::Kuter => 8,
            self::Niszczyciel => 20,
            self::Lotniskowiec => 45,
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
