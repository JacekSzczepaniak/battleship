<?php

namespace App\Domain\Expedition;

/**
 * Wybór kapitana przy spotkaniu, który nie wymaga bitwy ani zlecenia (#141).
 * Handel i bitwa (napad, walka) mają własne akcje.
 */
enum EncounterDecision: string
{
    /** Minięcie handlarza bez skutków. */
    case Pass = 'pass';

    /** Ucieczka przed piratem: tik i część ładunku. */
    case Flee = 'flee';

    /** Okup piratowi w złocie z ładowni. */
    case Ransom = 'ransom';
}
