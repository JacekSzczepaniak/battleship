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

    /**
     * Broń wynika ze składu floty: jedno użycie na statek-nośnik
     * (torpeda=niszczyciel, sonar=kuter, nalot=lotniskowiec; patrz stałe
     * Game::*_CARRIER_LENGTH). Dla floty klasycznej daje to dokładnie
     * dotychczasowe limity: torpeda 2, sonar 3, nalot 1.
     */
    public function weapons(): WeaponSpecs
    {
        $ships = $this->allowedShips();
        $torpedoes = $ships[Game::TORPEDO_CARRIER_LENGTH] ?? 0;
        $sonars = $ships[Game::SONAR_CARRIER_LENGTH] ?? 0;
        $airRaids = $ships[Game::AIR_RAID_CARRIER_LENGTH] ?? 0;

        return new WeaponSpecs(
            torpedo: new TorpedoSpec(uses: $torpedoes, diagonalUses: min(1, $torpedoes)),
            sonar: new SonarSpec(uses: $sonars, radius: 3),
            airRaid: new AirRaidSpec(uses: $airRaids, maxArea: new Area(3, 3)),
        );
    }
}
