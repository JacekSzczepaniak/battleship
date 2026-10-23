<?php

namespace App\Domain\Game\Siege;

use App\Domain\Game\Board;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Game\Weapon\Loadout;

/**
 * Deterministyczny układ fortu z seeda (#119, D4): ten sam seed = ten sam fort.
 * Własny LCG (jak WorldMap) — żadnego globalnego RNG. Konstrukcje mieszczą się
 * na planszy i nie nakładają się, ale — inaczej niż statki — mogą się stykać:
 * bez tego eksplozja 3×3 nie miałaby czego trafić. Magazyn stoi przy murze,
 * żeby jego wybuch zawsze coś niszczył. Liczby w FortRules.
 */
final class FortGenerator
{
    private const MAX_ATTEMPTS = 200;

    public function generate(int $seed): Fort
    {
        $state = $seed & 0x7FFFFFFF;
        $next = static function (int $mod) use (&$state): int {
            $state = (1103515245 * $state + 12345) & 0x7FFFFFFF;

            return $state % max(1, $mod);
        };

        $size = new BoardSize(FortRules::SIZE, FortRules::SIZE);
        $board = new Board($size, contactAllowed: true);

        // najpierw mury (najdłuższe najtrudniej upchnąć), potem magazyn i działa
        $walls = [];
        for ($i = 0; $i < FortRules::WALLS; ++$i) {
            $length = FortRules::WALL_MIN_LENGTH + $next(FortRules::WALL_MAX_LENGTH - FortRules::WALL_MIN_LENGTH + 1);
            $walls[] = $this->placeRandom($board, $length, $next);
        }
        $magazine = $this->placeNextTo($board, $walls[$next(count($walls))], $next)
            ?? $this->placeRandom($board, 1, $next)->start;
        $guns = [];
        for ($i = 0; $i < FortRules::GUNS; ++$i) {
            $guns[] = $this->placeRandom($board, 1, $next)->start;
        }

        return new Fort($seed, $guns, $walls, $magazine);
    }

    /**
     * Pole jednopolowej konstrukcji przy danym murze (także po skosie), losowo
     * spośród wolnych; null, gdy mur jest otoczony.
     *
     * @param callable(int):int $next
     */
    private function placeNextTo(Board $board, Ship $wall, callable $next): ?Coordinate
    {
        $free = [];
        foreach ($wall->cells() as $c) {
            for ($dy = -1; $dy <= 1; ++$dy) {
                for ($dx = -1; $dx <= 1; ++$dx) {
                    $x = $c->x + $dx;
                    $y = $c->y + $dy;
                    if ($x < 0 || $y < 0 || $x >= $board->size->width || $y >= $board->size->height || isset($free["$x:$y"])) {
                        continue;
                    }
                    $free["$x:$y"] = new Coordinate($x, $y);
                }
            }
        }

        $candidates = array_values($free);
        while ([] !== $candidates) {
            $i = $next(count($candidates));
            $ship = new Ship($candidates[$i], Orientation::H, 1, Loadout::none());
            try {
                $board->place($ship);

                return $ship->start;
            } catch (\DomainException) {
                array_splice($candidates, $i, 1);
            }
        }

        return null;
    }

    /** @param callable(int):int $next */
    private function placeRandom(Board $board, int $length, callable $next): Ship
    {
        $size = $board->size;
        for ($attempt = 0; $attempt < self::MAX_ATTEMPTS; ++$attempt) {
            $orientation = 0 === $next(2) ? Orientation::H : Orientation::V;
            $maxX = Orientation::H === $orientation ? $size->width - $length : $size->width - 1;
            $maxY = Orientation::V === $orientation ? $size->height - $length : $size->height - 1;
            $ship = new Ship(new Coordinate($next($maxX + 1), $next($maxY + 1)), $orientation, $length, Loadout::none());
            try {
                $board->place($ship);

                return $ship;
            } catch (\DomainException) {
                // kolizja albo styk — losujemy dalej
            }
        }

        // przy liczbach z FortRules na 10×10 nieosiągalne; pierwsze wolne pole wierszami
        for ($y = 0; $y < $size->height; ++$y) {
            for ($x = 0; $x + $length <= $size->width; ++$x) {
                $ship = new Ship(new Coordinate($x, $y), Orientation::H, $length, Loadout::none());
                try {
                    $board->place($ship);

                    return $ship;
                } catch (\DomainException) {
                }
            }
        }

        throw new \DomainException('Fort does not fit on board');
    }
}
