<?php

namespace App\Domain\Expedition;

/**
 * Powód, dla którego budynku nie da się teraz postawić. Jedno źródło reguł
 * dla budowy (wyjątek z komunikatem) i dla UI panelu osady (kod blokady).
 */
enum BuildingBlock: string
{
    case NotOwned = 'not-owned';
    case AlreadyBuilt = 'already-built';
    case Site = 'site';
    case NotPresent = 'not-present';
    case Resources = 'resources';

    /** Komunikat wyjątku domenowego (mapowany na kod API w ExceptionSubscriber). */
    public function message(): string
    {
        return match ($this) {
            self::NotOwned => 'Island not owned',
            self::AlreadyBuilt => 'Building already standing',
            self::Site => 'Building site not suitable',
            self::NotPresent => 'Not at island',
            self::Resources => 'Not enough resources',
        };
    }
}
