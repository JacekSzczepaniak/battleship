<?php

namespace App\Domain\Game;

/**
 * Przewagi gracza w bitwie z perków kapitana (Epic K, #161). Gra nie zna
 * perków: dostaje gotowe liczby przy starcie bitwy wyprawy. Bitwa treningowa
 * i wróg ich nie mają (D6).
 */
final class BattlePerks
{
    public function __construct(
        /** Dodatkowe użycia sonaru gracza w bitwie (gdy skład ma nośnik sonaru). */
        public readonly int $extraSonarUses = 0,
        /** Dodatkowa energia w pierwszej turze gracza. */
        public readonly int $firstTurnEnergy = 0,
        /** O ile niższy jest próg rzutu abordażu. */
        public readonly int $boardingEdge = 0,
    ) {
        if ($extraSonarUses < 0 || $firstTurnEnergy < 0 || $boardingEdge < 0) {
            throw new \InvalidArgumentException('Negative battle perk value');
        }
    }

    public static function none(): self
    {
        return new self();
    }
}
