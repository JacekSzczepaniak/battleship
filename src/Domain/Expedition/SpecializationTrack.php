<?php

namespace App\Domain\Expedition;

/**
 * Ścieżka specjalizacji kapitana (Epic K, #160): bojowa i ekonomiczna
 * rozwijają się równolegle, wyłącznie z akcji gracza (D1). Każda ma
 * 5 poziomów o wspólnych progach punktowych (D2).
 */
enum SpecializationTrack: string
{
    case Combat = 'combat';
    case Economy = 'economy';

    /** Punkty potrzebne do poziomów 1–5 (D2, decyzja Jacka 25.10.2026). */
    public const THRESHOLDS = [20, 60, 120, 200, 300];

    public const MAX_LEVEL = 5;

    /** Poziom ścieżki przy danej liczbie punktów: 0 przed pierwszym progiem. */
    public static function levelFor(int $points): int
    {
        $level = 0;
        foreach (self::THRESHOLDS as $threshold) {
            if ($points < $threshold) {
                break;
            }
            ++$level;
        }

        return $level;
    }

    /** Próg punktowy poziomu 1–5; null poza zakresem. */
    public static function threshold(int $level): ?int
    {
        return self::THRESHOLDS[$level - 1] ?? null;
    }

    /** @return list<Perk> perki ścieżki od poziomu 1 do 5 */
    public function perks(): array
    {
        $perks = array_values(array_filter(Perk::cases(), fn (Perk $perk) => $perk->track() === $this));
        usort($perks, static fn (Perk $a, Perk $b) => $a->level() <=> $b->level());

        return $perks;
    }
}
