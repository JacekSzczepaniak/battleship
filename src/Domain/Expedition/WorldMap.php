<?php

namespace App\Domain\Expedition;

/**
 * Mapa wolnego morza generowana deterministycznie z seeda świata (LCG —
 * żadnego globalnego RNG; ten sam seed = ten sam świat, co daje powtarzalne
 * testy i replay). Wyspy trasy lądują w pionowych pasmach coraz dalej od
 * startu — progresja przestrzenna odpowiada progresji rang. Startem wyprawy
 * jest sektor pierwszej wyspy (Zatoka Rozbitka).
 */
final class WorldMap
{
    public const WIDTH = 12;
    public const HEIGHT = 12;

    /** @param array<string, array{x:int,y:int}> $islands */
    private function __construct(private array $islands)
    {
    }

    /** @param list<string> $islandIds wyspy w kolejności trasy */
    public static function generate(int $seed, array $islandIds): self
    {
        $state = $seed & 0x7FFFFFFF;
        $next = static function (int $mod) use (&$state): int {
            $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;

            return $state % max(1, $mod);
        };

        $count = max(1, count($islandIds));
        $bandWidth = max(1, intdiv(self::WIDTH, $count));

        $islands = [];
        foreach ($islandIds as $i => $id) {
            $islands[$id] = [
                'x' => min(self::WIDTH - 1, $i * $bandWidth + $next($bandWidth)),
                'y' => $next(self::HEIGHT),
            ];
        }

        return new self($islands);
    }

    public function isInside(int $x, int $y): bool
    {
        return $x >= 0 && $y >= 0 && $x < self::WIDTH && $y < self::HEIGHT;
    }

    /** @return array{x:int,y:int} sektor startu wyprawy (pierwsza wyspa trasy) */
    public function startPosition(): array
    {
        $first = reset($this->islands);
        if (false === $first) {
            throw new \DomainException('World has no islands');
        }

        return $first;
    }

    /** Id wyspy stojącej na sektorze; null = otwarte morze. */
    public function islandAt(int $x, int $y): ?string
    {
        foreach ($this->islands as $id => $pos) {
            if ($pos['x'] === $x && $pos['y'] === $y) {
                return $id;
            }
        }

        return null;
    }

    /** @return array{x:int,y:int}|null */
    public function islandPosition(string $islandId): ?array
    {
        return $this->islands[$islandId] ?? null;
    }

    /** @return array<string, array{x:int,y:int}> */
    public function islands(): array
    {
        return $this->islands;
    }
}
