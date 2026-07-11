<?php

namespace App\Domain\Expedition;

/**
 * Definicja wyspy wyprawy: bitwa o zadanych zasadach, bramkowana rangą,
 * z nagrodami XP i materiałów za wygraną i (mniejszymi) za przegraną —
 * z porażek też się uczymy. Stocznia na wyspie buduje i remontuje flotę;
 * jej poziom ogranicza dostępne typy statków.
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
        public readonly int $materialsWin,
        public readonly int $materialsLoss,
        public readonly int $shipyardLevel,
        public readonly int $boardWidth,
        public readonly int $boardHeight,
    ) {
        if (!in_array($mode, ['classic', 'fun'], true)) {
            throw new \InvalidArgumentException('Island mode must be classic|fun');
        }
        if ($xpWin < 0 || $xpLoss < 0 || $xpLoss > $xpWin) {
            throw new \InvalidArgumentException('Invalid island XP rewards');
        }
        if ($materialsWin < 0 || $materialsLoss < 0 || $materialsLoss > $materialsWin) {
            throw new \InvalidArgumentException('Invalid island material rewards');
        }
        if ($shipyardLevel < 0 || $boardWidth < 5 || $boardHeight < 5) {
            throw new \InvalidArgumentException('Invalid island parameters');
        }
    }

    public function isAccessibleFor(Rank $rank): bool
    {
        return $rank->atLeast($this->requiredRank);
    }
}
