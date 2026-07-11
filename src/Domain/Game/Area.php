<?php

namespace App\Domain\Game;

/**
 * Rozmiar prostokątnego obszaru. W żądaniu nalotu width/height to pół-zasięgi
 * od punktu centralnego (0 = pojedynczy wiersz/kolumna); w AirRaidSpec::maxArea
 * — maksymalny pełny rozmiar obszaru w komórkach.
 */
final class Area
{
    public function __construct(public readonly int $width, public readonly int $height)
    {
        if ($width < 0 || $height < 0) {
            throw new \InvalidArgumentException('Negative size');
        }
    }
}
