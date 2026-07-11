<?php

namespace App\Domain\Game\Weapon;

use App\Domain\Game\Area;

/**
 * Komplet parametrów broni specjalnych wariantu zasad (kształt, zasięg, użycia).
 * uses = 0 oznacza broń niedostępną w danym wariancie.
 */
final class WeaponSpecs
{
    public function __construct(
        public readonly TorpedoSpec $torpedo,
        public readonly SonarSpec $sonar,
        public readonly AirRaidSpec $airRaid,
    ) {
    }

    /** Wariant bez broni specjalnych (classic). */
    public static function none(): self
    {
        return new self(
            torpedo: new TorpedoSpec(uses: 0),
            sonar: new SonarSpec(uses: 0),
            airRaid: new AirRaidSpec(uses: 0, maxArea: new Area(0, 0)),
        );
    }

    /**
     * Limity użyć w płaskim kształcie stanu broni — klucze są stabilne
     * (snapshot, API i frontend na nich polegają).
     *
     * @return array{torpedo:int, sonar:int, airRaid:int, torpedoDiagonal:int}
     */
    public function limits(): array
    {
        return [
            'torpedo' => $this->torpedo->uses,
            'sonar' => $this->sonar->uses,
            'airRaid' => $this->airRaid->uses,
            'torpedoDiagonal' => $this->torpedo->diagonalUses,
        ];
    }
}
