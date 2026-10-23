<?php

namespace App\Domain\Expedition;

/**
 * Wpis dziennika kapitana: tik zdarzenia, jego rodzaj i dane potrzebne do
 * treści (id wyspy, bramy, technologii, typ statku, surowce). Dane to
 * wartości proste i tablice — trafiają wprost do snapshotu i API.
 */
final class LogEntry
{
    /** @param array<string, mixed> $data */
    public function __construct(
        public readonly int $tick,
        public readonly LogEvent $event,
        public readonly array $data = [],
    ) {
    }
}
