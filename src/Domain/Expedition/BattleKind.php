<?php

namespace App\Domain\Expedition;

/**
 * Rodzaj bitwy w rejestrze profilu. Bilans W/P wyspy liczy wszystkie rodzaje;
 * banderę stawia tylko wygrane zdobycie (GAME_DESIGN.md, filar III).
 */
enum BattleKind: string
{
    /** Bitwa o wyspę, z której kapitan może zrobić posiadłość. */
    case Capture = 'capture';
}
