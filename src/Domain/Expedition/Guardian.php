<?php

namespace App\Domain\Expedition;

/**
 * Strażnik strzeżonej cieśniny (Epic I): bitwa o zadanych zasadach z flotą
 * wroga o stałym składzie, niezależnym od składu gracza (jak napastnik
 * w obronie, #79), z domyślnym wyposażeniem. Nagrody jak za wyspę: XP
 * i surowce za wygraną, mniejsze za przegraną.
 */
final class Guardian
{
    /**
     * @param list<ShipType> $fleet flota strażnika
     */
    public function __construct(
        public readonly string $name,
        public readonly string $description,
        public readonly Rank $requiredRank,
        public readonly string $mode, // 'classic' | 'fun'
        public readonly array $fleet,
        public readonly int $xpWin,
        public readonly int $xpLoss,
        public readonly Resources $lootWin,
        public readonly Resources $lootLoss,
        public readonly int $boardWidth,
        public readonly int $boardHeight,
    ) {
        if (!in_array($mode, ['classic', 'fun'], true)) {
            throw new \InvalidArgumentException('Guardian mode must be classic|fun');
        }
        if ([] === $fleet || in_array(ShipType::Transportowiec, $fleet, true)) {
            throw new \InvalidArgumentException('Guardian needs a fleet of warships');
        }
        if ($xpWin < 0 || $xpLoss < 0 || $xpLoss > $xpWin || $lootLoss->total() > $lootWin->total()) {
            throw new \InvalidArgumentException('Invalid guardian rewards');
        }
        if ($boardWidth < 5 || $boardHeight < 5) {
            throw new \InvalidArgumentException('Invalid guardian board');
        }
    }

    /** @return array<int,int> skład floty strażnika: długość => liczba sztuk */
    public function composition(): array
    {
        $composition = [];
        foreach ($this->fleet as $type) {
            $composition[$type->length()] = ($composition[$type->length()] ?? 0) + 1;
        }
        krsort($composition);

        return $composition;
    }

    /** Pola planszy zajęte przez flotę strażnika. */
    public function fleetCells(): int
    {
        return array_sum(array_map(static fn (ShipType $type) => $type->length(), $this->fleet));
    }

    /** Limit pól składu gracza: 20% planszy, jak przy wyspach (#73). */
    public function squadCellLimit(): int
    {
        return intdiv($this->boardWidth * $this->boardHeight * 20, 100);
    }
}
