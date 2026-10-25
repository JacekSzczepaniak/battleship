<?php

namespace App\Domain\Expedition;

/**
 * Powód, dla którego brama akwenu jest zamknięta dla kapitana (Epic I).
 * Każdy typ bramy dokłada swoje powody: strzeżona cieśnina rangę i strażnika,
 * otwarty ocean (#108) technologię, wody nieznane (#143) mapę akwenu.
 */
enum GateBlock: string
{
    /** Ranga kapitana poniżej progu bramy. */
    case Rank = 'rank';

    /** Strażnik cieśniny jeszcze nie został pokonany. */
    case Guardian = 'guardian';

    /** Kapitan nie zbadał technologii, która otwiera bramę (otwarty ocean, #108). */
    case Technology = 'technology';

    /** Kapitan nie ma mapy akwenu za bramą (wody nieznane, #143). */
    case Chart = 'chart';

    /** Komunikat błędu domenowego (mapowany na kod API). */
    public function message(): string
    {
        return match ($this) {
            self::Rank => 'Gate locked: requires higher rank',
            self::Guardian => 'Gate guarded: defeat the guardian first',
            self::Technology => 'Gate locked: requires technology',
            self::Chart => 'Gate locked: requires a sea chart',
        };
    }
}
