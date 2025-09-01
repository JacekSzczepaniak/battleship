<?php
namespace App\Domain\Game;

final class Coordinate
{
    public function __construct(public int $x, public int $y)
    {
        if ($x < 0 || $y < 0) {
            throw new \InvalidArgumentException("Negative coord");
        }
    }
}
