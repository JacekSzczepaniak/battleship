<?php

namespace App\Domain\Game\Boarding;

/**
 * Modyfikator progu rzutu abordażu (#118). Kolejne obniżenia (technologia,
 * perk z Epica K) dochodzą jako nowe implementacje na liście BoardingOdds.
 */
interface BoardingModifier
{
    /** Kod do API i UI, np. 'half-hull'. */
    public function code(): string;

    /** O ile modyfikator obniża próg dla tego celu; 0 = nie działa. */
    public function reduction(BoardingTarget $target): int;
}
