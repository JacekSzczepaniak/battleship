<?php

namespace App\Domain\Expedition;

/**
 * Definicja wyspy wyprawy: bitwa o zadanych zasadach, bramkowana rangą,
 * z nagrodami XP i surowców za wygraną i (mniejszymi) za przegraną —
 * z porażek też się uczymy. Stocznia na wyspie buduje i remontuje flotę;
 * jej poziom ogranicza dostępne typy statków (0 = brak stoczni, tylko
 * tratwa). Złoże wyspy produkuje surowiec co tik, odkąd kapitan wygrał
 * o nią bitwę (GAME_DESIGN.md, filar III).
 */
final class Island
{
    /** Urobek czeka w magazynie wyspy najwyżej tyle tików produkcji — potem złoże staje. */
    public const DEPOSIT_BUFFER_TICKS = 10;

    public function __construct(
        public readonly string $id,
        public readonly string $name,
        public readonly string $description,
        public readonly Rank $requiredRank,
        public readonly string $mode, // 'classic' | 'fun' — wariant zasad bitwy
        public readonly int $xpWin,
        public readonly int $xpLoss,
        public readonly Resources $lootWin,
        public readonly Resources $lootLoss,
        public readonly int $shipyardLevel,
        public readonly int $boardWidth,
        public readonly int $boardHeight,
        public readonly Resources $depositYield = new Resources(),
    ) {
        if (!in_array($mode, ['classic', 'fun'], true)) {
            throw new \InvalidArgumentException('Island mode must be classic|fun');
        }
        if ($xpWin < 0 || $xpLoss < 0 || $xpLoss > $xpWin) {
            throw new \InvalidArgumentException('Invalid island XP rewards');
        }
        if ($lootLoss->total() > $lootWin->total()) {
            throw new \InvalidArgumentException('Invalid island resource rewards');
        }
        if ($shipyardLevel < 0 || $boardWidth < 5 || $boardHeight < 5) {
            throw new \InvalidArgumentException('Invalid island parameters');
        }
    }

    /**
     * Ile pól planszy może zająć skład wysłany do bitwy: 20% planszy, jak
     * w klasycznych statkach (20 pól na 10×10) — przy gęstszej flocie trudniej
     * spudłować niż trafić. Przeciwnik dostaje lustrzany skład, więc limit
     * działa w obie strony (#73).
     */
    public function squadCellLimit(): int
    {
        return intdiv($this->boardWidth * $this->boardHeight * 20, 100);
    }

    public function hasDeposit(): bool
    {
        return !$this->depositYield->isZero();
    }

    /** Ile urobku może czekać w magazynie wyspy, zanim złoże stanie. */
    public function depositCap(): Resources
    {
        return $this->depositYield->times(self::DEPOSIT_BUFFER_TICKS);
    }

    public function isAccessibleFor(Rank $rank): bool
    {
        return $rank->atLeast($this->requiredRank);
    }
}
