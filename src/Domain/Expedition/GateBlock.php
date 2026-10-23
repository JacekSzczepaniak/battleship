<?php

namespace App\Domain\Expedition;

/**
 * Powód, dla którego brama akwenu jest zamknięta dla kapitana (Epic I).
 * Każdy typ bramy dokłada swoje powody: strzeżona cieśnina rangę i strażnika,
 * otwarty ocean (#108) technologię.
 */
enum GateBlock: string
{
    /** Ranga kapitana poniżej progu bramy. */
    case Rank = 'rank';

    /** Strażnik cieśniny jeszcze nie został pokonany. */
    case Guardian = 'guardian';

    /** Komunikat błędu domenowego (mapowany na kod API). */
    public function message(): string
    {
        return match ($this) {
            self::Rank => 'Gate locked: requires higher rank',
            self::Guardian => 'Gate guarded: defeat the guardian first',
        };
    }
}
