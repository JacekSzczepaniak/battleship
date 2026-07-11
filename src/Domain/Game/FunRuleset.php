<?php

namespace App\Domain\Game;

final class FunRuleset implements Ruleset
{
    public function __construct(private BoardSize $size = new BoardSize(10, 10))
    {
    }

    public function name(): string
    {
        return 'fun';
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
        return new Area(3, 3);
    }

    public function weaponLimits(): array
    {
        // torpedoDiagonal = ile z torped może płynąć po przekątnej (podzbiór limitu torpedo)
        return ['torpedo' => 2, 'sonar' => 3, 'airRaid' => 1, 'torpedoDiagonal' => 1];
    }
}
