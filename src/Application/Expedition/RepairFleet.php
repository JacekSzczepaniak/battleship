<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\FleetRepair;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;

final class RepairFleet
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Remontuje całą flotę w stoczni wyspy, na której kapitan stoi (#151):
     * bramka obecności i stoczni jak przy złomowaniu, a poziom stoczni
     * (z rozbudową) i kolejność od najtańszego rozstrzyga domena.
     *
     * @return array{repair: FleetRepair, stockpile: Resources, cargo: Resources}
     */
    public function handle(string $profileId, string $islandId): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $island = $this->islands->byId($islandId);
        if (null === $island) {
            throw new \DomainException('Island not found');
        }
        if (!$profile->isAt($this->worldFactory->worldFor($profile), $island->id)) {
            throw new \DomainException('Not at island');
        }
        $shipyardLevel = $profile->shipyardLevel($island);
        if ($shipyardLevel < 1) {
            throw new \DomainException('No shipyard on this island');
        }

        $repair = $profile->repairFleet($island->id, $shipyardLevel);
        $this->profiles->save($profile);

        return ['repair' => $repair, 'stockpile' => $profile->stockpile($island->id), 'cargo' => $profile->cargo()];
    }
}
