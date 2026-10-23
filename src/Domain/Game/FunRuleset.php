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
    /** Energia na turę (#59): pula stała, wzrost z rangą/technologią to Epic K (#58). */
    public const ENERGY_PER_TURN = 3;

    /**
     * @param array<int,int>|null           $ships            skład floty: długość => liczba sztuk (null = flota klasyczna)
     * @param array<int,int>|null           $opponentShips    skład floty przeciwnika (null = lustrzany)
     * @param array<int,list<Loadout>>|null $variants         warianty wyposażenia floty (null = same domyślne)
     * @param array<int,list<Loadout>>|null $opponentVariants warianty przeciwnika (null = lustrzane)
     * @param int|null                      $sonarRadius      promień sonaru gracza (null = SonarSpec::RADIUS);
     *                                                        technologie gracza nie przechodzą do lustra
     */
    public function __construct(
        private BoardSize $size = new BoardSize(10, 10),
        private ?array $ships = null,
        private ?array $opponentShips = null,
        private ?array $variants = null,
        private ?array $opponentVariants = null,
        private ?int $sonarRadius = null,
    ) {
        if (null !== $sonarRadius && $sonarRadius < 0) {
            throw new \InvalidArgumentException('Negative sonar radius');
        }
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
        return self::weaponsFor($this->allowedShips(), $this->variants(), $this->sonarRadius());
    }

    /** Wróg zawsze z bazowym promieniem sonaru: technologie gracza nie przechodzą do lustra (#54, D6). */
    public function opponentWeapons(): WeaponSpecs
    {
        return self::weaponsFor($this->opponentShips(), $this->opponentVariants(), SonarSpec::RADIUS);
    }

    public function energyPerTurn(): int
    {
        return self::ENERGY_PER_TURN;
    }

    /** Promień sonaru gracza — ustalony przy starcie gry i zapisany w jej snapshotcie. */
    public function sonarRadius(): int
    {
        return $this->sonarRadius ?? SonarSpec::RADIUS;
    }

    /**
     * @param array<int,int>           $ships
     * @param array<int,list<Loadout>> $variants
     */
    private static function weaponsFor(array $ships, array $variants, int $sonarRadius): WeaponSpecs
    {
        $roster = FleetComposition::roster($ships, $variants);
        $uses = static fn (WeaponKind $weapon): int => array_sum(array_map(
            static fn (array $ship) => $ship['loadout']->usesOf($weapon),
            $roster,
        ));
        $torpedoes = $uses(WeaponKind::Torpedo);

        return new WeaponSpecs(
            torpedo: new TorpedoSpec(uses: $torpedoes, diagonalUses: min(1, $torpedoes)),
            sonar: new SonarSpec(uses: $uses(WeaponKind::Sonar), radius: $sonarRadius),
            airRaid: new AirRaidSpec(uses: $uses(WeaponKind::AirRaid), maxArea: new Area(3, 3)),
        );
    }
}
