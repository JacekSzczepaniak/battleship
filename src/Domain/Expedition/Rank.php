<?php

namespace App\Domain\Expedition;

/**
 * Ranga kapitana wynikająca wprost z XP (progi rosnące). XP nigdy nie ginie,
 * więc ranga też nie spada. Bramkuje dostęp do wysp (docelowo też typów statków).
 */
enum Rank: string
{
    case Rozbitek = 'rozbitek';
    case Marynarz = 'marynarz';
    case Kapitan = 'kapitan';
    case Admiral = 'admiral';

    /** Minimalny XP wymagany dla rangi. */
    public function threshold(): int
    {
        return match ($this) {
            self::Rozbitek => 0,
            self::Marynarz => 80,
            self::Kapitan => 250,
            self::Admiral => 500,
        };
    }

    public static function fromXp(int $xp): self
    {
        $rank = self::Rozbitek;
        foreach (self::ordered() as $candidate) {
            if ($xp >= $candidate->threshold()) {
                $rank = $candidate;
            }
        }

        return $rank;
    }

    /** @return list<self> rangi od najniższej do najwyższej */
    public static function ordered(): array
    {
        return [self::Rozbitek, self::Marynarz, self::Kapitan, self::Admiral];
    }

    public function atLeast(self $required): bool
    {
        return $this->threshold() >= $required->threshold();
    }

    /** Następna ranga do zdobycia; null dla najwyższej. */
    public function next(): ?self
    {
        $ordered = self::ordered();
        $index = array_search($this, $ordered, true);

        return $ordered[$index + 1] ?? null;
    }
}
