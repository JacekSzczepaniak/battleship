<?php

namespace App\Domain\Expedition;

/**
 * Wody nieznane (#143, Epic J D5): bramę otwiera mapa akwenu docelowego
 * w profilu kapitana, bez bitwy. Mapy nie zużywa się, więc przejście działa
 * w obie strony i tyle razy, ile kapitan chce.
 */
final class ChartCondition implements GateCondition
{
    public function __construct(public readonly string $seaId)
    {
    }

    public function blocker(CaptainProfile $profile, string $gateId): ?GateBlock
    {
        return $profile->hasSeaChart($this->seaId) ? null : GateBlock::Chart;
    }
}
