<?php

namespace App\Domain\Expedition;

/**
 * Mapa akwenu na sprzedaż w porcie handlowym (#143, Epic J D5): prowadzi przez
 * bramę „wody nieznane” na akwen `$seaId`. Cena bazowa w złocie; rynek mnoży
 * ją przez mnożnik ceny kapitana jak kursy towarów (reputacja z #142).
 */
final class SeaChartOffer
{
    public function __construct(
        public readonly string $seaId,
        public readonly int $price,
    ) {
        if ('' === $seaId || $price < 1) {
            throw new \InvalidArgumentException('Sea chart offer needs a sea and a positive price');
        }
    }

    /** Cena w złocie przy mnożniku ceny rynku (w procentach), zaokrąglona w górę. */
    public function priceFor(int $pricePercent = MarketRate::BASE_PRICE_PERCENT): int
    {
        return intdiv($this->price * max(1, $pricePercent) + MarketRate::BASE_PRICE_PERCENT - 1, MarketRate::BASE_PRICE_PERCENT);
    }
}
