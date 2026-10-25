<?php

namespace App\Domain\Game\Boarding;

/** Perk kapitana „próg abordażu −1” (Epic K, #161): obniża próg każdej próby. */
final class CaptainBoardingEdge implements BoardingModifier
{
    public function __construct(private int $reduction = 1)
    {
    }

    public function code(): string
    {
        return 'captain';
    }

    public function reduction(BoardingTarget $target): int
    {
        return $this->reduction;
    }
}
