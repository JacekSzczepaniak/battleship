<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\WeaponSpecs;

interface Ruleset
{
    /** Identyfikator wariantu zasad: 'classic' | 'fun' (do snapshotu i API). */
    public function name(): string;

    public function boardSize(): BoardSize;

    /**
     * @return array<int,int> mapowanie: długość => dozwolona liczba sztuk
     */
    public function allowedShips(): array;

    /**
     * Parametry broni specjalnych wariantu (kształt, zasięg, limity użyć);
     * uses = 0 oznacza broń niedostępną.
     */
    public function weapons(): WeaponSpecs;
}
