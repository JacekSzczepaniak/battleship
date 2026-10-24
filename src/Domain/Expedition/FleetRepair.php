<?php

namespace App\Domain\Expedition;

/**
 * Wynik remontu całej floty (#151): wyremontowane statki w kolejności remontu,
 * pominięte z powodem i łączny koszt, który zapłacił kapitan.
 */
final readonly class FleetRepair
{
    /**
     * @param list<OwnedShip>                                  $repaired
     * @param list<array{ship: OwnedShip, reason: RepairSkip}> $skipped
     */
    public function __construct(
        public array $repaired,
        public array $skipped,
        public Resources $paid,
    ) {
    }
}
