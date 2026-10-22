<?php

namespace App\Domain\Game\Weapon;

/**
 * Rodzaj broni specjalnej. Wartości są kluczami stanu broni
 * (WeaponSpecs::limits(), liczniki użyć w Game, snapshot i API).
 */
enum WeaponKind: string
{
    case Torpedo = 'torpedo';
    case Sonar = 'sonar';
    case AirRaid = 'airRaid';
}
