<?php

namespace App\Domain\Expedition;

/**
 * Poziom reputacji kapitana na osi kupiec ↔ korsarz (#142, D4 Epica J).
 * Każdy poziom ma inne skutki na rynku i przy spotkaniach.
 */
enum ReputationTier: string
{
    /** Od +40: ceny na rynku o 10% niższe. */
    case Merchant = 'merchant';

    /** Bez skutków: ceny bazowe. */
    case Neutral = 'neutral';

    /** Od −40: ceny na rynku o 25% wyższe. */
    case Corsair = 'corsair';

    /** Od −70: port odmawia handlu, zamiast handlarza przychodzą łowcy nagród. */
    case Embargo = 'embargo';
}
