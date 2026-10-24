<?php

namespace App\Domain\Expedition;

/**
 * Typ statku floty wyprawy. Długość wiąże typ z bitwą (z długości wynika też
 * domyślne wyposażenie statku, Loadout::defaultFor), a koszty i bramki (ranga, poziom stoczni) napędzają ekonomię.
 * Tratwa jest zawsze darmowa — bezpiecznik anty-softlock: rozbitek zawsze
 * może wypłynąć (docs/GAME_DESIGN.md, filar 5).
 */
enum ShipType: string
{
    case Tratwa = 'tratwa';
    case Kuter = 'kuter';
    case Niszczyciel = 'niszczyciel';
    case Lotniskowiec = 'lotniskowiec';
    case Transportowiec = 'transportowiec';

    /** Długość statku na planszy bitwy. */
    public function length(): int
    {
        return match ($this) {
            self::Tratwa => 1,
            self::Kuter => 2,
            self::Niszczyciel => 3,
            self::Lotniskowiec => 4,
            // długość bez domyślnej broni (Loadout::defaultFor) — w bitwie zwykły cel
            self::Transportowiec => 5,
        };
    }

    /** Typ statku o danej długości na planszy (zdobycz z abordażu, #118); null = brak takiego typu. */
    public static function ofLength(int $length): ?self
    {
        foreach (self::cases() as $type) {
            if ($type->length() === $length) {
                return $type;
            }
        }

        return null;
    }

    /**
     * Nośnik broni w bitwie (kuter: sonar, niszczyciel: torpeda, lotniskowiec:
     * nalot) — tylko taki statek eskortuje konwój.
     */
    public function isArmed(): bool
    {
        return match ($this) {
            self::Kuter, self::Niszczyciel, self::Lotniskowiec => true,
            self::Tratwa, self::Transportowiec => false,
        };
    }

    /**
     * Pojemność ładowni (suma surowców). Okręty wojenne wożą mało — duża
     * ładownia to rola transportowca (#47). Tratwa nie wozi nic: jest darmowa,
     * więc ładownia na tratwach byłaby darmowym transportem.
     */
    public function cargoCapacity(): int
    {
        return match ($this) {
            self::Tratwa => 0,
            self::Kuter => 15,
            self::Niszczyciel => 20,
            self::Lotniskowiec => 30,
            self::Transportowiec => 60,
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
            self::Transportowiec => new Resources(wood: 40, steel: 10),
        };
    }

    /**
     * Wartość złomu: połowa kosztu budowy, z zaokrągleniem w dół. Tyle daje
     * rozbiórka zdobyczy (#118) i złomowanie sprawnego statku (#124).
     */
    public function scrapValue(): Resources
    {
        return Resources::fromArray(array_map(static fn (int $amount) => intdiv($amount, 2), $this->buildCost()->toArray()));
    }

    /** Koszt remontu uszkodzonego statku. */
    public function repairCost(): Resources
    {
        return match ($this) {
            self::Tratwa => Resources::none(),
            self::Kuter => new Resources(wood: 8),
            self::Niszczyciel => new Resources(wood: 10, steel: 10),
            self::Lotniskowiec => new Resources(wood: 20, steel: 25),
            self::Transportowiec => new Resources(wood: 15, steel: 5),
        };
    }

    /** Minimalna ranga kapitana pozwalająca budować ten typ. */
    public function requiredRank(): Rank
    {
        return match ($this) {
            self::Tratwa, self::Kuter => Rank::Rozbitek,
            self::Niszczyciel, self::Transportowiec => Rank::Marynarz,
            self::Lotniskowiec => Rank::Kapitan,
        };
    }

    /**
     * Minimalny poziom stoczni potrzebny do budowy i remontu. Tratwę klei się
     * z desek na każdej wyspie, także bez stoczni (bezpiecznik anty-softlock).
     */
    public function requiredShipyardLevel(): int
    {
        return match ($this) {
            self::Tratwa => 0,
            self::Kuter => 1,
            self::Niszczyciel, self::Transportowiec => 2,
            self::Lotniskowiec => 3,
        };
    }
}
