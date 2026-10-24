<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;

final class Sail
{
    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Rejs w zasięgu profilu (1 sektor, z Żeglugą dwusektorową 2): mgła, kartografia i eventy podróży liczy domena.
     *
     * @return array<string,mixed>
     */
    public function handle(string $profileId, int $x, int $y): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $world = $this->worldFactory->worldFor($profile);
        $outcome = $profile->sail($x, $y, $world);
        $this->profiles->save($profile);

        $position = $profile->position();

        return [
            'position' => $position,
            'discoveredNow' => $outcome['discoveredNow'],
            'cartography' => $outcome['cartography'],
            'cartographyLost' => $outcome['cartographyLost'],
            'event' => $outcome['event'],
            // spotkanie z handlarzem albo piratem (#141): rejs stoi do decyzji; szczegóły w /expedition
            'encounter' => $outcome['encounter'],
            'cargo' => $profile->cargo()->toArray(),
            'cargoCapacity' => $profile->cargoCapacity(),
            'atIsland' => $world->islandAt($position['x'], $position['y']),
            // sektor bramy akwenu (#107): stąd bitwa ze strażnikiem i przejście
            'atGate' => $world->gateAt($position['x'], $position['y']),
        ];
    }
}
