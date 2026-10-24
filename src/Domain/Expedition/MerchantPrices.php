<?php

namespace App\Domain\Expedition;

/**
 * Ceny handlarza spotkanego na morzu (#141): kurs rynku akwenu z portu
 * handlowego (#140) z mnożnikiem ceny powyżej 100%. Zlecenie liczy się
 * w sztukach towaru: kupno płaci złotem za każdą zaczętą porcję, sprzedaż
 * dostaje złoto tylko za pełne porcje — każdy towar osobno.
 */
final class MerchantPrices
{
    public function __construct(
        public readonly MarketRate $rate,
        public readonly int $pricePercent,
    ) {
        if ($pricePercent < 1) {
            throw new \InvalidArgumentException('Price percent must be positive');
        }
    }

    /** Złoto za kupno drewna i stali (złoto w zleceniu się nie liczy). */
    public function buyCost(Resources $units): int
    {
        $wood = $this->rate->buyAmount(MarketGood::Wood, $this->pricePercent);
        $steel = $this->rate->buyAmount(MarketGood::Steel, $this->pricePercent);

        return intdiv($units->wood + $wood - 1, $wood) + intdiv($units->steel + $steel - 1, $steel);
    }

    /** Złoto za sprzedaż drewna i stali: tylko pełne porcje. */
    public function sellValue(Resources $units): int
    {
        return intdiv($units->wood, $this->rate->sellAmount(MarketGood::Wood, $this->pricePercent))
            + intdiv($units->steel, $this->rate->sellAmount(MarketGood::Steel, $this->pricePercent));
    }
}
