<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\AirRaidSpec;
use App\Domain\Game\Weapon\SonarSpec;
use App\Domain\Game\Weapon\TorpedoSpec;
use App\Domain\Game\Weapon\WeaponSpecs;

final class FunRuleset implements Ruleset
{
    /** @param array<int,int>|null $ships skład floty: długość => liczba sztuk (null = flota klasyczna) */
    public function __construct(
        private BoardSize $size = new BoardSize(10, 10),
        private ?array $ships = null,
    ) {
        FleetComposition::assertValid($ships);
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
        return $this->ships ?? FleetComposition::CLASSIC;
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
