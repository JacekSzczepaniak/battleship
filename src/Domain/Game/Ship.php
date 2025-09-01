<?php

namespace App\Domain\Game;

final class Ship
{
    public function __construct(
        public Coordinate $start,
        public Orientation $orientation,
        public int $length,
    ) {
        if ($this->length < 1) {
            throw new \InvalidArgumentException('Ship length must be >= 1');
        }
    }

    /** @return Coordinate[] */
    public function cells(): array
    {
        $cells = [];
        for ($i = 0; $i < $this->length; ++$i) {
            $x = $this->start->x + (Orientation::H === $this->orientation ? $i : 0);
            $y = $this->start->y + (Orientation::V === $this->orientation ? $i : 0);
            $cells[] = new Coordinate($x, $y);
        }

        return $cells;
    }
}
