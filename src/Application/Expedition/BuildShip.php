<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\ShipType;
use App\Domain\Shared\ProfileId;

final class BuildShip
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
    ) {
    }

    /**
     * Buduje statek w stoczni wyspy: wyspa musi być dostępna (ranga),
     * stocznia wystarczająco duża, a bramkę rangi typu i koszt materiałów
     * egzekwuje domena (CaptainProfile::buildShip).
     */
    public function handle(string $profileId, string $islandId, ShipType $type): OwnedShip
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $island = $this->islands->byId($islandId);
        if (null === $island) {
            throw new \DomainException('Island not found');
        }
        if (!$island->isAccessibleFor($profile->rank())) {
            throw new \DomainException(sprintf('Island locked: requires rank %s', $island->requiredRank->value));
        }
        if ($island->shipyardLevel < $type->requiredShipyardLevel()) {
            throw new \DomainException('Shipyard level too low for this ship type');
        }

        $ship = $profile->buildShip($type);
        $this->profiles->save($profile);

        return $ship;
    }
}
