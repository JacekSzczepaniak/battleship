<?php

namespace App\Application\Game;

/**
 * AI nigdy nie sięga po bronie — wiring env testowego (deterministyczna
 * tura AI: zawsze pojedynczy strzał).
 */
final class NeverUseWeaponsDecider implements WeaponUseDecider
{
    public function decide(int $percent): bool
    {
        return false;
    }
}
