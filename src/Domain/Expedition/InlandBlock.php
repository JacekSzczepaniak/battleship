<?php

namespace App\Domain\Expedition;

/**
 * Powód, dla którego ekspedycji w głąb dzikiej wyspy nie da się teraz
 * zacząć (#144). Jedno źródło reguł dla akcji (wyjątek z komunikatem)
 * i dla UI zakładki wyspy (kod blokady).
 */
enum InlandBlock: string
{
    case NotPresent = 'not-present';
    case Rank = 'rank';
    case InProgress = 'in-progress';
    case Resting = 'resting';
    case NoSeaworthyShips = 'no-seaworthy-ships';

    /** Komunikat wyjątku domenowego (mapowany na kod API w ExceptionSubscriber). */
    public function message(WildIsland $island): string
    {
        return match ($this) {
            self::NotPresent => 'Not at wild island',
            self::Rank => sprintf('Wild island locked: requires rank %s', $island->requiredRank->value),
            self::InProgress => 'Inland expedition in progress',
            self::Resting => 'Wild island needs rest',
            self::NoSeaworthyShips => 'No seaworthy ships',
        };
    }
}
