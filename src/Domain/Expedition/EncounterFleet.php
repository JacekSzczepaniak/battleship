<?php

namespace App\Domain\Expedition;

/**
 * Flota spotkana na otwartym morzu (#141): pirat albo handlarz z eskortą.
 * Bitwa o zadanych zasadach z flotą o stałym składzie (jak strażnik, #107),
 * bez lustra składu gracza. Za przegraną tylko XP, bez łupu.
 */
final class EncounterFleet
{
    /**
     * @param list<ShipType> $fleet
     */
    public function __construct(
        public readonly string $name,
        public readonly string $mode, // 'classic' | 'fun'
        public readonly array $fleet,
        public readonly int $xpWin,
        public readonly int $xpLoss,
        public readonly Resources $lootWin,
        public readonly int $boardWidth,
        public readonly int $boardHeight,
        public readonly Resources $lootLoss = new Resources(),
    ) {
        if (!in_array($mode, ['classic', 'fun'], true)) {
            throw new \InvalidArgumentException('Encounter mode must be classic|fun');
        }
        if ([] === $fleet) {
            throw new \InvalidArgumentException('Encounter needs a fleet');
        }
        if ($xpWin < 0 || $xpLoss < 0 || $xpLoss > $xpWin || $lootLoss->total() > $lootWin->total()) {
            throw new \InvalidArgumentException('Invalid encounter rewards');
        }
        if ($boardWidth < 5 || $boardHeight < 5) {
            throw new \InvalidArgumentException('Invalid encounter board');
        }
    }

    /** @return array<int,int> skład floty: długość => liczba sztuk */
    public function composition(): array
    {
        $composition = [];
        foreach ($this->fleet as $type) {
            $composition[$type->length()] = ($composition[$type->length()] ?? 0) + 1;
        }
        krsort($composition);

        return $composition;
    }

    /** Limit pól składu gracza: 20% planszy, jak przy wyspach (#73). */
    public function squadCellLimit(): int
    {
        return intdiv($this->boardWidth * $this->boardHeight * 20, 100);
    }
}
