<?php

namespace App\Domain\Expedition;

/**
 * Reputacja kapitana (#142, D4 Epica J): jedna oś od −100 (korsarz) do +100
 * (kupiec), start od 0. Handel ją podnosi, napad na handlarza obniża,
 * pokonany pirat podnosi. Progi zmieniają ceny rynku, a najniższy włącza
 * embargo i łowców nagród. Liczby zatwierdził Jacek 24.10.2026.
 */
final class Reputation
{
    public const MIN = -100;
    public const MAX = 100;

    /** Zmiany: transakcja na rynku albo zlecenie u handlarza, pokonany pirat, napad na handlarza. */
    public const TRADE = 2;
    public const PIRATE_DEFEATED = 5;
    public const RAID = -20;

    /** Progi (włącznie): od MERCHANT_FROM w górę kupiec, od CORSAIR_FROM w dół korsarz, od EMBARGO_FROM w dół embargo. */
    public const MERCHANT_FROM = 40;
    public const CORSAIR_FROM = -40;
    public const EMBARGO_FROM = -70;

    /** Mnożnik ceny rynku w procentach dla kupca i korsarza (100 = kurs bazowy). */
    public const MERCHANT_PRICE_PERCENT = 90;
    public const CORSAIR_PRICE_PERCENT = 125;

    public function __construct(public readonly int $value = 0)
    {
        if ($value < self::MIN || $value > self::MAX) {
            throw new \InvalidArgumentException('Reputation out of range');
        }
    }

    /** Wartość spoza osi (np. ze starego zapisu) przycięta do zakresu. */
    public static function clamped(int $value): self
    {
        return new self(max(self::MIN, min(self::MAX, $value)));
    }

    public function change(int $delta): self
    {
        return self::clamped($this->value + $delta);
    }

    public function tier(): ReputationTier
    {
        return match (true) {
            $this->value >= self::MERCHANT_FROM => ReputationTier::Merchant,
            $this->value <= self::EMBARGO_FROM => ReputationTier::Embargo,
            $this->value <= self::CORSAIR_FROM => ReputationTier::Corsair,
            default => ReputationTier::Neutral,
        };
    }

    /** Mnożnik ceny rynku w procentach; przy embargu port i tak nie handluje. */
    public function pricePercent(): int
    {
        return match ($this->tier()) {
            ReputationTier::Merchant => self::MERCHANT_PRICE_PERCENT,
            ReputationTier::Neutral => MarketRate::BASE_PRICE_PERCENT,
            ReputationTier::Corsair, ReputationTier::Embargo => self::CORSAIR_PRICE_PERCENT,
        };
    }

    public function isEmbargo(): bool
    {
        return ReputationTier::Embargo === $this->tier();
    }
}
