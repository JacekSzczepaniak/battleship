<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\Loadout;
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
     * Skład floty przeciwnika (AI); zwykle lustrzany, czyli równy allowedShips().
     *
     * @return array<int,int> mapowanie: długość => liczba sztuk
     */
    public function opponentShips(): array;

    /**
     * Warianty wyposażenia floty gracza: długość => wyposażenia statków, które
     * odbiegają od domyślnego dla długości (patrz FleetComposition::roster()).
     *
     * @return array<int,list<Loadout>>
     */
    public function variants(): array;

    /**
     * Warianty floty przeciwnika; zwykle lustrzane (D6 w #54: wariant to skład,
     * więc przechodzi do lustra), przycięte do jego składu.
     *
     * @return array<int,list<Loadout>>
     */
    public function opponentVariants(): array;

    /**
     * Parametry broni specjalnych wariantu (kształt, zasięg, limity użyć);
     * uses = 0 oznacza broń niedostępną.
     */
    public function weapons(): WeaponSpecs;

    /** Parametry broni przeciwnika: jak weapons(), ale liczone z jego składu i wyposażenia. */
    public function opponentWeapons(): WeaponSpecs;
}
