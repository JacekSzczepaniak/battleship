<?php

namespace App\Domain\Expedition;

use Symfony\Component\Uid\Uuid;

/**
 * Egzemplarz statku we flocie kapitana. Uszkodzony statek nie wypływa
 * do bitwy — wymaga remontu w stoczni (za materiały).
 */
final class OwnedShip
{
    public function __construct(
        public readonly string $id,
        public readonly ShipType $type,
        private bool $damaged = false,
    ) {
    }

    public static function build(ShipType $type): self
    {
        return new self(Uuid::v4()->toRfc4122(), $type);
    }

    public function isDamaged(): bool
    {
        return $this->damaged;
    }

    public function markDamaged(): void
    {
        $this->damaged = true;
    }

    public function repair(): void
    {
        $this->damaged = false;
    }
}
