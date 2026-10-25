<?php

namespace App\Domain\Expedition;

/**
 * Warunek otwarcia bramy akwenu (Epic I, D3). Typ bramy to implementacja:
 * strzeżona cieśnina (GuardianCondition), otwarty ocean z technologią (TechnologyCondition, #108),
 * wody nieznane z mapą akwenu (ChartCondition, #143).
 * Warunek tylko czyta profil; stan, który trzeba zdobyć (pokonany strażnik),
 * trzyma profil pod id bramy.
 */
interface GateCondition
{
    /** Dlaczego brama jest zamknięta dla kapitana; null = można przepłynąć. */
    public function blocker(CaptainProfile $profile, string $gateId): ?GateBlock;
}
