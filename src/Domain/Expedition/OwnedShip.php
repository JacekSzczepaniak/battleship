<?php

namespace App\Domain\Expedition;

use Symfony\Component\Uid\Uuid;

/**
 * Egzemplarz statku we flocie kapitana. Uszkodzony statek nie wypływa
 * do bitwy — wymaga remontu w stoczni (za surowce). Wariant (ShipVariant)
 * jest stały; typ z wariantami bez wskazanego dostaje domyślny.
 */
final class OwnedShip
{
    public readonly ?ShipVariant $variant;

    public function __construct(
        public readonly string $id,
        public readonly ShipType $type,
        private bool $damaged = false,
        ?ShipVariant $variant = null,
    ) {
        if (null !== $variant && $variant->type() !== $type) {
            throw new \InvalidArgumentException('Ship variant not available for this ship type');
        }
        $this->variant = $variant ?? ShipVariant::defaultFor($type);
    }

    public static function build(ShipType $type, ?ShipVariant $variant = null): self
    {
        return new self(Uuid::v4()->toRfc4122(), $type, variant: $variant);
    }

    /** Nośnik broni (eskorta konwoju): wariant bez broni, np. kuter remontowy (#152), nim nie jest. */
    public function isArmed(): bool
    {
        return $this->variant?->isArmed() ?? $this->type->isArmed();
    }

    /** Kuter remontowy (#152): naprawia flotę poza stocznią, gdy jest sprawny. */
    public function isFieldRepairer(): bool
    {
        return ShipVariant::KuterRemontowy === $this->variant;
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
