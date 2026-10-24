<?php

namespace App\Domain\Expedition;

/**
 * Spotkanie na morzu (#141, filar VI): kogo flota spotkała w rejsie.
 */
enum EncounterType: string
{
    /** Handlarz: handel na miejscu, napad albo minięcie. */
    case Merchant = 'merchant';

    /** Pirat: walka, ucieczka albo okup. */
    case Pirate = 'pirate';
}
