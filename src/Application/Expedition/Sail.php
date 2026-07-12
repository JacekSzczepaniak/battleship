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
     * Żegluga o jeden sektor: mgła, kartografia i eventy podróży liczy domena.
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
            'event' => $outcome['event'],
            'materials' => $profile->materials(),
            'atIsland' => $world->islandAt($position['x'], $position['y']),
        ];
    }
}
