<?php

namespace App\Domain\Expedition;

/**
 * Ranga kapitana wynikająca wprost z XP (progi rosnące). XP nigdy nie ginie,
 * więc ranga też nie spada. Bramkuje dostęp do wysp i typów statków.
 *
 * Drabina 9 stopni (GAME_DESIGN.md, filar VIII): zysk XP do kolejnej rangi
 * rośnie (30 → 400), więc jedna wygrana nie daje dwóch awansów naraz.
 * Rangi bez bramki (majtek, bosman, sternik, porucznik, komandor, admirał)
 * czekają na treść kolejnych akwenów.
 */
enum Rank: string
{
    case Rozbitek = 'rozbitek';
    case Majtek = 'majtek';
    case Marynarz = 'marynarz';
    case Bosman = 'bosman';
    case Sternik = 'sternik';
    case Porucznik = 'porucznik';
    case Kapitan = 'kapitan';
    case Komandor = 'komandor';
    case Admiral = 'admiral';

    /** Minimalny XP wymagany dla rangi. */
    public function threshold(): int
    {
        return match ($this) {
            self::Rozbitek => 0,
            self::Majtek => 30,
            self::Marynarz => 80,
            self::Bosman => 150,
            self::Sternik => 250,
            self::Porucznik => 400,
            self::Kapitan => 600,
            self::Komandor => 900,
            self::Admiral => 1300,
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
        return [
            self::Rozbitek, self::Majtek, self::Marynarz, self::Bosman, self::Sternik,
            self::Porucznik, self::Kapitan, self::Komandor, self::Admiral,
        ];
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
