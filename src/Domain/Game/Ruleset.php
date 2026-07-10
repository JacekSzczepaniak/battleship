<?php

namespace App\Domain\Game;

interface Ruleset
{
    public function boardSize(): BoardSize;

    /**
     * @return array<int,int> mapowanie: długość => dozwolona liczba sztuk
     */
    public function allowedShips(): array;

    /**
     * Maksymalny pełny rozmiar obszaru nalotu (w komórkach).
     */
    public function airRaidSize(): Area;

    public function fireTorpedo(): bool;
}
