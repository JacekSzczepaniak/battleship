<?php

namespace App\Domain\Expedition;

/**
 * Definicja wyspy wyprawy: bitwa o zadanych zasadach, bramkowana rangą,
 * z nagrodą XP za wygraną i (mniejszą) za przegraną — z porażek też się uczymy.
 */
final class Island
{
    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly Rank $requiredRank,
        public readonly string $mode, // 'classic' | 'fun' — wariant zasad bitwy
        public readonly int $xpWin,
        public readonly int $xpLoss,
    ) {
        if (!in_array($mode, ['classic', 'fun'], true)) {
            throw new \InvalidArgumentException('Island mode must be classic|fun');
        }
        if ($xpWin < 0 || $xpLoss < 0 || $xpLoss > $xpWin) {
            throw new \InvalidArgumentException('Invalid island XP rewards');
        }
    }

    public function isAccessibleFor(Rank $rank): bool
    {
        return $rank->atLeast($this->requiredRank);
    }
}
