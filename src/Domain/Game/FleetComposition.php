<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\Loadout;

/**
 * Skład floty jako mapa: długość statku => liczba sztuk, plus opcjonalne
 * warianty wyposażenia (statki tej samej długości z inną bronią, #95).
 * Domyślnym składem jest flota klasyczna; niestandardowe składy
 * (np. flota wyprawy budowana w stoczniach) przekazuje się do rulesetu.
 */
final class FleetComposition
{
    /** Flota klasyczna: 1×4, 2×3, 3×2, 4×1. */
    public const CLASSIC = [4 => 1, 3 => 2, 2 => 3, 1 => 4];

    private const MAX_SHIP_LENGTH = 6;
    private const MAX_SHIPS_PER_LENGTH = 20;

    /** @param array<int,int>|null $ships */
    public static function assertValid(?array $ships): void
    {
        if (null === $ships) {
            return;
        }
        if ([] === $ships) {
            throw new \InvalidArgumentException('Fleet composition must not be empty');
        }
        foreach ($ships as $length => $count) {
            if ($length < 1 || $length > self::MAX_SHIP_LENGTH) {
                throw new \InvalidArgumentException('Invalid ship length in fleet composition');
            }
            if ($count < 1 || $count > self::MAX_SHIPS_PER_LENGTH) {
                throw new \InvalidArgumentException('Invalid ship count in fleet composition');
            }
        }
    }

    /**
     * Warianty wyposażenia: długość => wyposażenia statków tej długości, które
     * odbiegają od domyślnego (Loadout::defaultFor). Pozostałe statki danej
     * długości mają wyposażenie domyślne. Wariantów nie może być więcej niż statków.
     *
     * @param array<int,int>                $ships
     * @param array<int,list<Loadout>>|null $variants
     */
    public static function assertValidVariants(array $ships, ?array $variants): void
    {
        foreach ($variants ?? [] as $length => $loadouts) {
            if (count($loadouts) > ($ships[$length] ?? 0)) {
                throw new \InvalidArgumentException('Ship variants exceed fleet composition');
            }
        }
    }

    /**
     * Wyposażenia wszystkich statków floty (warianty + domyślne dla reszty).
     *
     * @param array<int,int>           $ships
     * @param array<int,list<Loadout>> $variants
     *
     * @return list<array{length:int, loadout:Loadout}>
     */
    public static function roster(array $ships, array $variants): array
    {
        ksort($ships);
        $roster = [];
        foreach ($ships as $length => $count) {
            $own = $variants[$length] ?? [];
            for ($i = 0; $i < $count; ++$i) {
                $roster[] = ['length' => $length, 'loadout' => $own[$i] ?? Loadout::defaultFor($length)];
            }
        }

        return $roster;
    }

    /**
     * Warianty przycięte do składu (lustro wroga przy mniejszej flocie, #79):
     * przy braku miejsca odpadają najpierw statki z wyposażeniem domyślnym.
     *
     * @param array<int,list<Loadout>> $variants
     * @param array<int,int>           $ships
     *
     * @return array<int,list<Loadout>>
     */
    public static function variantsWithin(array $variants, array $ships): array
    {
        $out = [];
        foreach ($variants as $length => $loadouts) {
            $kept = array_slice($loadouts, 0, $ships[$length] ?? 0);
            if ([] !== $kept) {
                $out[$length] = $kept;
            }
        }

        return $out;
    }

    /**
     * Dokłada warianty do wolnych miejsc w składzie (wróg z wariantami ze
     * zdobyczy, #121): wariant zajmuje statek z wyposażeniem domyślnym danej
     * długości, a bez wolnego miejsca odpada. Warianty już nadane zostają.
     *
     * @param array<int,list<Loadout>> $variants
     * @param array<int,int>           $ships
     * @param array<int,list<Loadout>> $extra
     *
     * @return array<int,list<Loadout>>
     */
    public static function addVariants(array $variants, array $ships, array $extra): array
    {
        foreach ($extra as $length => $loadouts) {
            foreach ($loadouts as $loadout) {
                if (count($variants[$length] ?? []) < ($ships[$length] ?? 0)) {
                    $variants[$length][] = $loadout;
                }
            }
        }

        return $variants;
    }

    /**
     * Nadaje wygenerowanej flocie wyposażenie z wariantów: pierwsze statki
     * danej długości dostają warianty, reszta zostaje z domyślnym.
     *
     * @param Ship[]                   $ships
     * @param array<int,list<Loadout>> $variants
     *
     * @return Ship[]
     */
    public static function equip(array $ships, array $variants): array
    {
        $next = [];
        $out = [];
        foreach ($ships as $ship) {
            $i = $next[$ship->length] = ($next[$ship->length] ?? -1) + 1;
            $variant = $variants[$ship->length][$i] ?? null;
            $out[] = null === $variant ? $ship : new Ship($ship->start, $ship->orientation, $ship->length, $variant);
        }

        return $out;
    }

    /**
     * Warianty z kształtu snapshotu/API: {długość: [{broń: użycia}, …]}.
     *
     * @return array<int,list<Loadout>>
     */
    public static function variantsFromArray(mixed $raw): array
    {
        if (null === $raw || [] === $raw) {
            return [];
        }
        if (!is_array($raw)) {
            throw new \InvalidArgumentException('Invalid ship variants');
        }
        $variants = [];
        foreach ($raw as $length => $loadouts) {
            if (!is_array($loadouts) || !array_is_list($loadouts)) {
                throw new \InvalidArgumentException('Invalid ship variants');
            }
            foreach ($loadouts as $loadout) {
                if (!is_array($loadout)) {
                    throw new \InvalidArgumentException('Invalid ship variants');
                }
                $variants[(int) $length][] = Loadout::fromArray($loadout);
            }
        }

        return $variants;
    }

    /**
     * @param array<int,list<Loadout>> $variants
     *
     * @return array<int,list<array<string,int>>>
     */
    public static function variantsToArray(array $variants): array
    {
        return array_map(
            static fn (array $loadouts) => array_map(static fn (Loadout $l) => $l->toArray(), $loadouts),
            $variants,
        );
    }

    /**
     * Skład bez jednego statku najdłuższego (przy kilku równych odpada jeden).
     * Flota jednostatkowa zostaje bez zmian — bitwa bez floty nie ma sensu.
     *
     * @param array<int,int> $ships
     *
     * @return array<int,int>
     */
    public static function withoutLargest(array $ships): array
    {
        if (array_sum($ships) <= 1) {
            return $ships;
        }
        $largest = max(array_keys($ships));
        if (--$ships[$largest] <= 0) {
            unset($ships[$largest]);
        }

        return $ships;
    }

    /** @param array<int,int> $ships */
    public static function isClassic(array $ships): bool
    {
        ksort($ships);
        $classic = self::CLASSIC;
        ksort($classic);

        return $ships === $classic;
    }
}
