<?php

namespace App\Domain\Game;

final class ClassicRuleset implements Ruleset
{
    public function __construct(private BoardSize $size = new BoardSize(10, 10))
    {
    }

    public function boardSize(): BoardSize
    {
        return $this->size;
    }

    public function allowedShips(): array
    {
        // classic fleet: 1x4, 2x3, 3x2, 4x1
        return [4 => 1, 3 => 2, 2 => 3, 1 => 4];
    }
}
