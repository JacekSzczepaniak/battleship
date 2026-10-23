<?php

namespace App\Domain\Game\Boarding;

/**
 * Rzut k6 abordażu: deterministyczny z seeda gry i numeru tury, bez
 * globalnego RNG — ten sam stan bitwy zawsze daje ten sam wynik.
 */
final class BoardingRoll
{
    public static function d6(string $seed, int $round): int
    {
        return (crc32("boarding:{$seed}:{$round}") % 6) + 1;
    }
}
