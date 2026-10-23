<?php

namespace App\Domain\Game;

/**
 * Działanie strony w turze bitwy i jego koszt energii (#59, wariant B).
 * Atak (salwa, torpeda, nalot) kończy turę; działania wspierające (sonar,
 * ruch statku z #60) tylko zużywają energię. Klucze = nazwy w API.
 */
enum BattleAction: string
{
    case Shot = 'shot';
    case Sonar = 'sonar';
    case Torpedo = 'torpedo';
    case AirRaid = 'airRaid';
    case Move = 'move';

    public function cost(): int
    {
        return match ($this) {
            self::Shot, self::Sonar, self::Move => 1,
            self::Torpedo => 2,
            self::AirRaid => 3,
        };
    }

    /** @return array<string,int> koszty wszystkich działań (do API) */
    public static function costs(): array
    {
        $out = [];
        foreach (self::cases() as $action) {
            $out[$action->value] = $action->cost();
        }

        return $out;
    }
}
