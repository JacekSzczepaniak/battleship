<?php

namespace App\Domain\Game\Weapon;

/**
 * Parametry torpedy: liczba użyć na grę i stronę oraz ile z nich może płynąć
 * po przekątnej (podzbiór puli użyć; patrz Direction::isDiagonal()).
 */
final class TorpedoSpec
{
    public function __construct(
        public readonly int $uses,
        public readonly int $diagonalUses = 0,
    ) {
        if ($uses < 0 || $diagonalUses < 0) {
            throw new \InvalidArgumentException('Negative torpedo uses');
        }
        if ($diagonalUses > $uses) {
            throw new \InvalidArgumentException('Diagonal uses exceed torpedo uses');
        }
    }
}
