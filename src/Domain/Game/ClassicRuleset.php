<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\WeaponSpecs;

final class ClassicRuleset implements Ruleset
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
        return 'classic';
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
        return WeaponSpecs::none();
    }
}
