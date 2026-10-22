<?php

namespace App\Domain\Game\Weapon;

/**
 * Wyposażenie statku w bitwie: rodzaj broni => liczba użyć, które statek
 * wnosi do puli floty. Statek z bronią jest jej nośnikiem — pula danej broni
 * działa, dopóki pływa choć jeden jej nośnik.
 *
 * Domyślne wyposażenie wynika z długości (kuter=2: sonar, niszczyciel=3:
 * torpeda, lotniskowiec=4: nalot); warianty statków (#99) niosą inne.
 */
final class Loadout
{
    /** Górna granica użyć jednej broni na statek (bezpiecznik wejścia API). */
    private const MAX_USES = 5;

    /** @param array<string,int> $uses klucze = WeaponKind::value, wartości >= 1 */
    private function __construct(private readonly array $uses)
    {
    }

    public static function none(): self
    {
        return new self([]);
    }

    public static function of(WeaponKind $weapon, int $uses = 1): self
    {
        return self::fromArray([$weapon->value => $uses]);
    }

    public static function defaultFor(int $length): self
    {
        return match ($length) {
            2 => self::of(WeaponKind::Sonar),
            3 => self::of(WeaponKind::Torpedo),
            4 => self::of(WeaponKind::AirRaid),
            default => self::none(),
        };
    }

    /**
     * Wyposażenie z mapy broń => użycia (kształt snapshotu i API).
     *
     * @param array<mixed> $raw
     */
    public static function fromArray(array $raw): self
    {
        $uses = [];
        foreach ($raw as $weapon => $count) {
            if (null === WeaponKind::tryFrom((string) $weapon)) {
                throw new \InvalidArgumentException('Invalid weapon in ship loadout');
            }
            if (!is_int($count) || $count < 1 || $count > self::MAX_USES) {
                throw new \InvalidArgumentException('Invalid weapon uses in ship loadout');
            }
            $uses[(string) $weapon] = $count;
        }
        ksort($uses);

        return new self($uses);
    }

    public function usesOf(WeaponKind $weapon): int
    {
        return $this->uses[$weapon->value] ?? 0;
    }

    /** Czy statek jest nośnikiem danej broni. */
    public function carries(WeaponKind $weapon): bool
    {
        return $this->usesOf($weapon) > 0;
    }

    /** @return array<string,int> */
    public function toArray(): array
    {
        return $this->uses;
    }

    public function equals(self $other): bool
    {
        return $this->uses === $other->uses;
    }

    /** Stabilny klucz do porównywania składów floty (np. "sonar:2"). */
    public function key(): string
    {
        $parts = [];
        foreach ($this->uses as $weapon => $count) {
            $parts[] = $weapon.':'.$count;
        }

        return implode(',', $parts);
    }
}
