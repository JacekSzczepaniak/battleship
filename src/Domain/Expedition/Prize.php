<?php

namespace App\Domain\Expedition;

use Symfony\Component\Uid\Uuid;

/**
 * Statek zdobyty abordażem (#118), czekający w profilu na decyzję kapitana
 * (PrizeDecision). Typ wynika z długości statku wroga, wariant z jego wyposażenia.
 */
final class Prize
{
    public function __construct(
        public readonly string $id,
        public readonly ShipType $type,
        public readonly ?ShipVariant $variant,
        public readonly int $tick,
    ) {
        if (null !== $variant && $variant->type() !== $type) {
            throw new \InvalidArgumentException('Ship variant not available for this ship type');
        }
    }

    public static function capture(ShipType $type, ?ShipVariant $variant, int $tick): self
    {
        return new self(Uuid::v4()->toRfc4122(), $type, $variant, $tick);
    }

    /** Zwrot z rozbiórki: połowa kosztu budowy typu, z zaokrągleniem w dół. */
    public function scrapValue(): Resources
    {
        $cost = $this->type->buildCost();

        return new Resources(intdiv($cost->wood, 2), intdiv($cost->steel, 2), intdiv($cost->gold, 2));
    }

    /** @return array{id:string, type:string, variant:?string, tick:int} */
    public function toArray(): array
    {
        return ['id' => $this->id, 'type' => $this->type->value, 'variant' => $this->variant?->value, 'tick' => $this->tick];
    }
}
