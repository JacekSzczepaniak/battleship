<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;

final class RepairShip
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Remontuje statek w stoczni wyspy, na której kapitan stoi — te same
     * bramki co budowa (obecność, poziom stoczni), koszt egzekwuje domena.
     */
    public function handle(string $profileId, string $islandId, string $shipId): OwnedShip
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

        $ship = null;
        foreach ($profile->fleet() as $candidate) {
            if ($candidate->id === $shipId) {
                $ship = $candidate;
                break;
            }
        }
        if (null !== $ship && $island->shipyardLevel < $ship->type->requiredShipyardLevel()) {
            throw new \DomainException('Shipyard level too low for this ship type');
        }

        $repaired = $profile->repairShip($shipId);
        $this->profiles->save($profile);

        return $repaired;
    }
}
