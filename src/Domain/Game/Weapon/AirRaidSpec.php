<?php

namespace App\Domain\Game\Weapon;

use App\Domain\Game\Area;

/**
 * Parametry nalotu: liczba użyć na grę i stronę oraz maksymalny pełny rozmiar
 * ostrzeliwanego obszaru w komórkach (żądanie nalotu posługuje się pół-zasięgami).
 */
final class AirRaidSpec
{
    public function __construct(
        public readonly int $uses,
        public readonly Area $maxArea,
    ) {
        if ($uses < 0) {
            throw new \InvalidArgumentException('Negative air raid uses');
        }
    }
}
