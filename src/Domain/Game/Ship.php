<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\Loadout;

final class Ship
{
    /** Wyposażenie statku; brak w konstruktorze = domyślne dla długości. */
    public Loadout $loadout;

    public function __construct(
        public Coordinate $start,
        public Orientation $orientation,
        public int $length,
        ?Loadout $loadout = null,
    ) {
        if ($this->length < 1) {
            throw new \InvalidArgumentException('Ship length must be >= 1');
        }
        $this->loadout = $loadout ?? Loadout::defaultFor($length);
    }

    public function hasDefaultLoadout(): bool
    {
        return $this->loadout->equals(Loadout::defaultFor($this->length));
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
