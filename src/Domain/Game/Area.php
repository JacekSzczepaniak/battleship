<?php

namespace App\Domain\Game;

final class Area
{

    public function __construct(Coordinate $start, public int $width, public int $height)
    {
        if ($width < 1 || $height < 1) {
            throw new \InvalidArgumentException('Negative size');
        }
    }
}
