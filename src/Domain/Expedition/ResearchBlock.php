<?php

namespace App\Domain\Expedition;

/**
 * Powód, dla którego badania nie da się teraz zacząć. Jedno źródło reguł dla
 * startu badania (wyjątek z komunikatem) i dla UI drzewka (kod blokady).
 */
enum ResearchBlock: string
{
    case Researched = 'researched';
    /** Technologia ze zdobyczy (#121): odblokowuje ją tylko zbadanie konstrukcji zdobytego statku. */
    case BoardingOnly = 'boarding-only';
    /** Legenda z questu (#144): odblokowuje ją tylko ekspedycja w głąb dzikiej wyspy. */
    case QuestOnly = 'quest-only';
    case Busy = 'busy';
    case Prerequisite = 'prerequisite';
    case Rank = 'rank';
    case NoAcademy = 'no-academy';
    case NotPresent = 'not-present';
    case Resources = 'resources';

    /** Komunikat wyjątku domenowego (mapowany na kod API w ExceptionSubscriber). */
    public function message(Technology $technology): string
    {
        return match ($this) {
            self::Researched => 'Technology already researched',
            self::BoardingOnly => 'Technology available only from boarded ships',
            self::QuestOnly => 'Technology available only from quests',
            self::Busy => 'Research already in progress',
            self::Prerequisite => 'Technology requires previous level in branch',
            self::Rank => sprintf('Technology locked: requires rank %s', $technology->academyLevel()->requiredRank()?->value),
            self::NoAcademy => 'No active academy on this island',
            self::NotPresent => 'Not at island',
            self::Resources => 'Not enough resources',
        };
    }
}
