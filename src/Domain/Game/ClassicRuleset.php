<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\Loadout;
use App\Domain\Game\Weapon\WeaponSpecs;

final class ClassicRuleset implements Ruleset
{
    /**
     * @param array<int,int>|null           $ships            skład floty: długość => liczba sztuk (null = flota klasyczna)
     * @param array<int,int>|null           $opponentShips    skład floty przeciwnika (null = lustrzany)
     * @param array<int,list<Loadout>>|null $variants         warianty wyposażenia (bez broni w classic, ale skład je zna)
     * @param array<int,list<Loadout>>|null $opponentVariants warianty przeciwnika (null = lustrzane)
     */
    public function __construct(
        private BoardSize $size = new BoardSize(10, 10),
        private ?array $ships = null,
        private ?array $opponentShips = null,
        private ?array $variants = null,
        private ?array $opponentVariants = null,
    ) {
        FleetComposition::assertValid($ships);
        FleetComposition::assertValid($opponentShips);
        FleetComposition::assertValidVariants($this->allowedShips(), $variants);
        FleetComposition::assertValidVariants($this->opponentShips(), $opponentVariants);
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

    public function variants(): array
    {
        return $this->variants ?? [];
    }

    public function opponentVariants(): array
    {
        return $this->opponentVariants ?? FleetComposition::variantsWithin($this->variants(), $this->opponentShips());
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
