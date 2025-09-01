<?php

namespace App\Domain\Game;

final class Ship
{
    public function __construct(
        public Coordinate  $start,
        public Orientation $orientation,
        public int         $length
    )
    {
        if ($this->length < 1) {
            throw new \InvalidArgumentException('Ship length must be >= 1');
        }
    }

    /** @return Coordinate[] */
    public function cells(): array
    {
        $cells = [];
        for ($i = 0; $i < $this->length; $i++) {
            $x = $this->start->x + ($this->orientation === Orientation::H ? $i : 0);
            $y = $this->start->y + ($this->orientation === Orientation::V ? $i : 0);
            $cells[] = new Coordinate($x, $y);
        }
        return $cells;
    }
}
