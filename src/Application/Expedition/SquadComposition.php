<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\OwnedShip;
use App\Domain\Game\Weapon\Loadout;

/** Skład bitewny floty wyprawy w kształcie, który przyjmuje gra. */
final class SquadComposition
{
    /**
     * Skład (długość => sztuki) i warianty statków (#99) jako wyposażenie
     * odbiegające od domyślnego dla długości.
     *
     * @param list<OwnedShip> $squad
     *
     * @return array{0: array<int,int>, 1: array<int,list<Loadout>>}
     */
    public static function of(array $squad): array
    {
        $composition = [];
        $variants = [];
        foreach ($squad as $ship) {
            $length = $ship->type->length();
            $composition[$length] = ($composition[$length] ?? 0) + 1;
            $loadout = $ship->variant?->loadout();
            if (null !== $loadout && !$loadout->equals(Loadout::defaultFor($length))) {
                $variants[$length][] = $loadout;
            }
        }

        return [$composition, $variants];
    }
}
