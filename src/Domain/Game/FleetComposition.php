<?php

namespace App\Domain\Game;

/**
 * Skład floty jako mapa: długość statku => liczba sztuk.
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
