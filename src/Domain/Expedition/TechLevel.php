<?php

namespace App\Domain\Expedition;

/**
 * Poziom w gałęzi drzewka: koszt, czas badania w tikach pod banderą i bramka
 * rangi są wspólne dla poziomu, nie dla technologii (Epic G, D3–D4).
 */
enum TechLevel: int
{
    case First = 1;
    case Second = 2;
    case Third = 3;

    public function researchCost(): Resources
    {
        return match ($this) {
            self::First => new Resources(wood: 20, steel: 20, gold: 5),
            self::Second => new Resources(wood: 40, steel: 30, gold: 15),
            self::Third => new Resources(wood: 60, steel: 50, gold: 30),
        };
    }

    public function researchTicks(): int
    {
        return match ($this) {
            self::First => 6,
            self::Second => 10,
            self::Third => 14,
        };
    }

    /** Ranga wymagana do badania; null = bez bramki. */
    public function requiredRank(): ?Rank
    {
        return match ($this) {
            self::First => null,
            self::Second => Rank::Sternik,
            self::Third => Rank::Kapitan,
        };
    }

    /** Poziom, który musi być zbadany w tej samej gałęzi; null dla pierwszego. */
    public function previous(): ?self
    {
        return self::tryFrom($this->value - 1);
    }

    /**
     * Reguła drzewka, niezależna od miejsca i surowców: poprzedni poziom
     * gałęzi zbadany, potem ranga.
     *
     * @param list<Technology> $researched
     */
    public function treeBlocker(TechBranch $branch, array $researched, Rank $rank): ?ResearchBlock
    {
        $previous = $this->previous();
        if (null !== $previous && [] === array_filter($researched, static fn (Technology $t) => $t->branch() === $branch && $t->level() === $previous)) {
            return ResearchBlock::Prerequisite;
        }
        $required = $this->requiredRank();
        if (null !== $required && !$rank->atLeast($required)) {
            return ResearchBlock::Rank;
        }

        return null;
    }
}
