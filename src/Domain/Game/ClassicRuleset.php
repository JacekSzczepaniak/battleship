<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\WeaponSpecs;

final class ClassicRuleset implements Ruleset
{
    /**
     * @param array<int,int>|null $ships         skład floty: długość => liczba sztuk (null = flota klasyczna)
     * @param array<int,int>|null $opponentShips skład floty przeciwnika (null = lustrzany)
     */
    public function __construct(
        private BoardSize $size = new BoardSize(10, 10),
        private ?array $ships = null,
        private ?array $opponentShips = null,
    ) {
        FleetComposition::assertValid($ships);
        FleetComposition::assertValid($opponentShips);
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

    public function opponentShips(): array
    {
        return $this->opponentShips ?? $this->allowedShips();
    }

    public function weapons(): WeaponSpecs
    {
        return WeaponSpecs::none();
    }

    public function opponentWeapons(): WeaponSpecs
    {
        return WeaponSpecs::none();
    }
}
