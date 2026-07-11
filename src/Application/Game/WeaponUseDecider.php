<?php

namespace App\Application\Game;

/**
 * Decyzja "czy AI ma teraz użyć broni" dla zadanego procentu szansy.
 * Produkcyjnie losowa; w env testowym podmieniana na deterministyczną
 * (testy funkcjonalne wymagają przewidywalnej tury AI).
 */
interface WeaponUseDecider
{
    public function decide(int $percent): bool;
}
