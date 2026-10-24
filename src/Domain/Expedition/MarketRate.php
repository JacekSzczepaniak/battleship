<?php

namespace App\Domain\Expedition;

/**
 * Stały kurs rynku akwenu (#140, Epic J D2): ile drewna i ile stali kupuje
 * 1 złoto. Sprzedaż idzie po połowie ceny kupna, czyli za 1 złoto oddaje się
 * dwa razy więcej towaru. Rynek nie ma dynamicznego popytu.
 *
 * Transakcje idą w porcjach po 1 złoto, więc nie ma ułamków. Mnożnik ceny
 * w procentach (100 = cena bazowa) zostawia miejsce na reputację z #142
 * i na gorsze ceny handlarza spotkanego na morzu (#141): powyżej 100 złoto
 * kupuje mniej towaru, a sprzedaż wymaga go więcej.
 */
final class MarketRate
{
    public const BASE_PRICE_PERCENT = 100;

    /** Sprzedaż po połowie ceny kupna. */
    public const SELL_SPREAD = 2;

    public function __construct(
        public readonly int $woodPerGold,
        public readonly int $steelPerGold,
    ) {
        if ($woodPerGold < 1 || $steelPerGold < 1) {
            throw new \InvalidArgumentException('Market rate must be positive');
        }
    }

    /** Ile towaru kupuje 1 złoto; drożej = mniej, ale nigdy mniej niż 1. */
    public function buyAmount(MarketGood $good, int $pricePercent = self::BASE_PRICE_PERCENT): int
    {
        return max(1, intdiv($this->perGold($good) * self::BASE_PRICE_PERCENT, self::percent($pricePercent)));
    }

    /** Ile towaru trzeba oddać za 1 złoto; drożej = więcej, z zaokrągleniem w górę. */
    public function sellAmount(MarketGood $good, int $pricePercent = self::BASE_PRICE_PERCENT): int
    {
        $base = self::BASE_PRICE_PERCENT;

        return intdiv($this->perGold($good) * self::SELL_SPREAD * self::percent($pricePercent) + $base - 1, $base);
    }

    /**
     * Ile złota warte są surowce sprzedane po kursie sprzedaży: złoto bez
     * zmian, drewno i stal przeliczone razem, z zaokrągleniem w dół z całości
     * (sprzedaż statku, #140).
     */
    public function goldValue(Resources $goods, int $pricePercent = self::BASE_PRICE_PERCENT): int
    {
        $wood = $this->sellAmount(MarketGood::Wood, $pricePercent);
        $steel = $this->sellAmount(MarketGood::Steel, $pricePercent);

        return $goods->gold + intdiv($goods->wood * $steel + $goods->steel * $wood, $wood * $steel);
    }

    /** @return array{wood: array{buy: int, sell: int}, steel: array{buy: int, sell: int}} towar za 1 złoto */
    public function toArray(int $pricePercent = self::BASE_PRICE_PERCENT): array
    {
        return [
            'wood' => ['buy' => $this->buyAmount(MarketGood::Wood, $pricePercent), 'sell' => $this->sellAmount(MarketGood::Wood, $pricePercent)],
            'steel' => ['buy' => $this->buyAmount(MarketGood::Steel, $pricePercent), 'sell' => $this->sellAmount(MarketGood::Steel, $pricePercent)],
        ];
    }

    private function perGold(MarketGood $good): int
    {
        return match ($good) {
            MarketGood::Wood => $this->woodPerGold,
            MarketGood::Steel => $this->steelPerGold,
        };
    }

    private static function percent(int $pricePercent): int
    {
        if ($pricePercent < 1) {
            throw new \InvalidArgumentException('Price percent must be positive');
        }

        return $pricePercent;
    }
}
