<?php

namespace App\Domain\Expedition;

/**
 * Otwarty ocean (#108): bramę otwiera zbadana technologia, bez bitwy i bez
 * stanu w profilu. Działa w obie strony, bo obie krawędzie grafu dzielą
 * jeden obiekt bramy.
 */
final class TechnologyCondition implements GateCondition
{
    public function __construct(public readonly Technology $technology)
    {
    }

    public function blocker(CaptainProfile $profile, string $gateId): ?GateBlock
    {
        return $profile->hasResearched($this->technology) ? null : GateBlock::Technology;
    }
}
