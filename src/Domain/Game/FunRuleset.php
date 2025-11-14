<?php

namespace App\Domain\Game;

final class FunRuleset implements Ruleset
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
        return [4 => 1, 3 => 2, 2 => 3, 1 => 4];
    }

    public function airRaidSize(): Area
    {
        return new Area(new Coordinate(2, 2), 3, 3);
    }

    public function fireTorpedo(): bool
    {
        return true;
    }
}
