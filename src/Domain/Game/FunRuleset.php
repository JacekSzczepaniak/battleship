<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\AirRaidSpec;
use App\Domain\Game\Weapon\SonarSpec;
use App\Domain\Game\Weapon\TorpedoSpec;
use App\Domain\Game\Weapon\WeaponSpecs;

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

    public function weapons(): WeaponSpecs
    {
        return new WeaponSpecs(
            torpedo: new TorpedoSpec(uses: 2, diagonalUses: 1),
            sonar: new SonarSpec(uses: 3, radius: 3),
            airRaid: new AirRaidSpec(uses: 1, maxArea: new Area(3, 3)),
        );
    }
}
