<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\AirRaidSpec;
use App\Domain\Game\Weapon\Loadout;
use App\Domain\Game\Weapon\SonarSpec;
use App\Domain\Game\Weapon\TorpedoSpec;
use App\Domain\Game\Weapon\WeaponKind;
use App\Domain\Game\Weapon\WeaponSpecs;

final class FunRuleset implements Ruleset
{
    /**
     * @param array<int,int>|null           $ships            skład floty: długość => liczba sztuk (null = flota klasyczna)
     * @param array<int,int>|null           $opponentShips    skład floty przeciwnika (null = lustrzany)
     * @param array<int,list<Loadout>>|null $variants         warianty wyposażenia floty (null = same domyślne)
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

    /**
     * Broń wynika z wyposażenia floty: suma użyć wniesionych przez statki
     * (domyślnie jedno na nośnik: torpeda=niszczyciel, sonar=kuter,
     * nalot=lotniskowiec; patrz Loadout::defaultFor). Dla floty klasycznej
     * daje to dokładnie dotychczasowe limity: torpeda 2, sonar 3, nalot 1.
     */
    public function weapons(): WeaponSpecs
    {
        return self::weaponsFor($this->allowedShips(), $this->variants());
    }

    public function opponentWeapons(): WeaponSpecs
    {
        return self::weaponsFor($this->opponentShips(), $this->opponentVariants());
    }

    /**
     * @param array<int,int>           $ships
     * @param array<int,list<Loadout>> $variants
     */
    private static function weaponsFor(array $ships, array $variants): WeaponSpecs
    {
        $roster = FleetComposition::roster($ships, $variants);
        $uses = static fn (WeaponKind $weapon): int => array_sum(array_map(
            static fn (array $ship) => $ship['loadout']->usesOf($weapon),
            $roster,
        ));
        $torpedoes = $uses(WeaponKind::Torpedo);

        return new WeaponSpecs(
            torpedo: new TorpedoSpec(uses: $torpedoes, diagonalUses: min(1, $torpedoes)),
            sonar: new SonarSpec(uses: $uses(WeaponKind::Sonar), radius: 3),
            airRaid: new AirRaidSpec(uses: $uses(WeaponKind::AirRaid), maxArea: new Area(3, 3)),
        );
    }
}
