<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Shared\ProfileId;

final class BuildShip
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Buduje statek w stoczni wyspy, na której kapitan STOI (geografia =
     * logistyka); stocznia musi być wystarczająco duża (z rozbudową pod
     * banderą, #82), a bramkę rangi typu, technologię wariantu
     * i koszt (magazyn wyspy, potem ładownia) egzekwuje domena (CaptainProfile::buildShip).
     */
    public function handle(string $profileId, string $islandId, ShipType $type, ?ShipVariant $variant = null): OwnedShip
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
        if ($profile->shipyardLevel($island) < $type->requiredShipyardLevel()) {
            throw new \DomainException('Shipyard level too low for this ship type');
        }

        $ship = $profile->buildShip($type, $island->id, $variant);
        $this->profiles->save($profile);

        return $ship;
    }
}
