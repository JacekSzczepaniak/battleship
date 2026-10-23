<?php

namespace App\Domain\Game\Boarding;

use App\Domain\Game\Ship;

/** Statek, na który gracz wchodzi abordażem, i liczba jego trafionych pól. */
final class BoardingTarget
{
    public function __construct(
        public readonly Ship $ship,
        public readonly int $hitCells,
    ) {
    }
}
