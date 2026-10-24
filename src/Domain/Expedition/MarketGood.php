<?php

namespace App\Domain\Expedition;

/**
 * Towar rynku portu handlowego (#140): drewno i stal kupuje się i sprzedaje
 * za złoto. Złoto jest walutą, nie towarem.
 */
enum MarketGood: string
{
    case Wood = 'wood';
    case Steel = 'steel';

    /** Paczka surowców z `$amount` jednostkami towaru. */
    public function resources(int $amount): Resources
    {
        return match ($this) {
            self::Wood => new Resources(wood: $amount),
            self::Steel => new Resources(steel: $amount),
        };
    }
}
