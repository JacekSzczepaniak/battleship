<?php

namespace App\Domain\Expedition;

/**
 * Liczniki akcji specjalizacji kapitana (Epic K, #160). Punkty i poziomy
 * wynikają z liczników, więc nie trzeba ich zapisywać, a ułamki (złoto,
 * ładunek, urobek) kumulują się między akcjami. Liczniki tylko rosną
 * i startują od zera, także w profilach sprzed specjalizacji (D7).
 */
final class Specializations
{
    /** @var array<string, int> klucz: SpecializationSource */
    private array $units = [];

    /** @param array<string, int> $units jednostki akcji per źródło; nieznane klucze i wartości ujemne pomijane */
    public function __construct(array $units = [])
    {
        foreach (SpecializationSource::cases() as $source) {
            $this->units[$source->value] = max(0, (int) ($units[$source->value] ?? 0));
        }
    }

    public function add(SpecializationSource $source, int $units): self
    {
        if ($units < 0) {
            throw new \InvalidArgumentException('Specialization units must not be negative');
        }
        $next = clone $this;
        $next->units[$source->value] += $units;

        return $next;
    }

    public function units(SpecializationSource $source): int
    {
        return $this->units[$source->value];
    }

    public function points(SpecializationTrack $track): int
    {
        $points = 0;
        foreach (SpecializationSource::cases() as $source) {
            if ($source->track() === $track) {
                $points += $source->points($this->units($source));
            }
        }

        return $points;
    }

    public function level(SpecializationTrack $track): int
    {
        return SpecializationTrack::levelFor($this->points($track));
    }

    /** @return array<string, int> */
    public function toArray(): array
    {
        return $this->units;
    }
}
