<?php

namespace App\Domain\Game\Boarding;

/** Co najmniej połowa pól statku trafiona: załoga osłabiona, próg niższy o 1. */
final class HalfHullHit implements BoardingModifier
{
    public function code(): string
    {
        return 'half-hull';
    }

    public function reduction(BoardingTarget $target): int
    {
        return 2 * $target->hitCells >= $target->ship->length ? 1 : 0;
    }
}
