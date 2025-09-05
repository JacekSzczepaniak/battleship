<?php

namespace App\Domain\Game;

final class Board
{
    /** @var Ship[] */
    private array $ships = [];

    public function __construct(public BoardSize $size)
    {
    }

    public function place(Ship $ship): void
    {
        // 1) board bounds
        foreach ($ship->cells() as $c) {
            if (!$this->isInside($c)) {
                throw new \DomainException('Ship out of board bounds');
            }
        }
        // 2) no collisions or contact (including diagonals)
        foreach ($this->ships as $other) {
            if ($this->touchOrOverlap($ship, $other)) {
                throw new \DomainException('Ships overlap or touch');
            }
        }
        $this->ships[] = $ship;
    }

    /** @param Ship[] $ships */
    public function placeMany(array $ships): void
    {
        foreach ($ships as $s) {
            $this->place($s);
        }
    }

    /** @return Ship[] */
    public function ships(): array
    {
        return $this->ships;
    }

    private function isInside(Coordinate $c): bool
    {
        return $c->x >= 0 && $c->y >= 0 && $c->x < $this->size->width && $c->y < $this->size->height;
    }

    private function touchOrOverlap(Ship $a, Ship $b): bool
    {
        $ac = $a->cells();
        $bc = $b->cells();

        $bSet = [];
        foreach ($bc as $c) {
            $bSet["{$c->x},{$c->y}"] = true;
        }

        // overlap
        foreach ($ac as $c) {
            if (isset($bSet["{$c->x},{$c->y}"])) {
                return true;
            }
        }

        // touching (8-neighborhood)
        $neighbors = function (Coordinate $c): array {
            $out = [];
            for ($dx = -1; $dx <= 1; ++$dx) {
                for ($dy = -1; $dy <= 1; ++$dy) {
                    if (0 === $dx && 0 === $dy) {
                        continue;
                    }
                    $out[] = [$c->x + $dx, $c->y + $dy];
                }
            }

            return $out;
        };

        $bNeighbors = [];
        foreach ($bc as $c) {
            foreach ($neighbors($c) as [$nx, $ny]) {
                $bNeighbors["$nx,$ny"] = true;
            }
        }

        foreach ($ac as $c) {
            if (isset($bNeighbors["{$c->x},{$c->y}"])) {
                return true;
            }
        }

        return false;
    }
}
