<?php

namespace App\Domain\Expedition;

/**
 * Strzeżona cieśnina: bramę otwiera na stałe, w obie strony, pierwsza wygrana
 * bitwa ze strażnikiem. Bitwa jest dostępna od rangi strażnika.
 */
final class GuardianCondition implements GateCondition
{
    public function __construct(public readonly Guardian $guardian)
    {
    }

    public function blocker(CaptainProfile $profile, string $gateId): ?GateBlock
    {
        return match (true) {
            $profile->isGateOpen($gateId) => null,
            !$profile->rank()->atLeast($this->guardian->requiredRank) => GateBlock::Rank,
            default => GateBlock::Guardian,
        };
    }
}
