<?php

namespace App\Application\Game;

final class RandomWeaponUseDecider implements WeaponUseDecider
{
    public function decide(int $percent): bool
    {
        return random_int(1, 100) <= $percent;
    }
}
