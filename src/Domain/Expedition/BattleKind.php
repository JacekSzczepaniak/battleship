<?php

namespace App\Domain\Expedition;

/**
 * Rodzaj bitwy w rejestrze profilu. Bilans W/P wyspy liczy wszystkie rodzaje;
 * banderę stawia tylko wygrane zdobycie, a odbiera przegrana obrona
 * (GAME_DESIGN.md, filar III). Bitwa ze strażnikiem toczy się o bramę akwenu.
 * Na twierdzy zdobycie to potyczka z flotą, a banderę stawia oblężenie (#120).
 */
enum BattleKind: string
{
    /** Bitwa o wyspę, z której kapitan może zrobić posiadłość. */
    case Capture = 'capture';

    /** Bitwa z kontratakiem na wyspie pod banderą — napastnik strzela pierwszy (#78). */
    case Defense = 'defense';

    /** Bitwa ze strażnikiem strzeżonej cieśniny — wygrana otwiera bramę akwenu (#107). */
    case Guardian = 'guardian';

    /** Oblężenie fortu oblężonej twierdzy — wygrana stawia banderę (#120). */
    case Siege = 'siege';
}
