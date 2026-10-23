<?php

namespace App\Domain\Expedition;

/**
 * Decyzja kapitana o statku zdobytym abordażem (#118). Kolejna opcja
 * to nowy case i gałąź w CaptainProfile::decidePrize().
 */
enum PrizeDecision: string
{
    /** Wyremontuj i dołącz: statek trafia do floty jako uszkodzony, bez wymogu rangi. */
    case Join = 'join';

    /** Rozbierz: połowa kosztu budowy do ładowni, nadmiar przepada. */
    case Scrap = 'scrap';

    /**
     * Zbadaj konstrukcję (#121): statek przepada, a jego wariant od razu
     * trafia do stoczni. Tylko wariant ze zdobyczy, którego kapitan nie zna.
     */
    case Study = 'study';
}
