<?php

namespace App\Domain\Game;

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
     * Maksymalny pełny rozmiar obszaru nalotu (w komórkach).
     */
    public function airRaidSize(): Area;

    /**
     * Limity użyć broni specjalnych na grę; 0 = broń niedostępna w tym wariancie.
     * torpedoDiagonal = ile z torped może płynąć po przekątnej (podzbiór torpedo).
     *
     * @return array{torpedo:int, sonar:int, airRaid:int, torpedoDiagonal:int}
     */
    public function weaponLimits(): array;
}
