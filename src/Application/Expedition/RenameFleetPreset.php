<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\FleetPreset;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;

final class RenameFleetPreset
{
    public function __construct(private ProfileRepository $profiles)
    {
    }

    /** Zmienia nazwę zestawu floty (#125); nazwa nie może dublować innego zestawu. */
    public function handle(string $profileId, string $presetId, string $name): FleetPreset
    {
        $profile = $this->profiles->get(new ProfileId($profileId)) ?? throw new \DomainException('Profile not found');

        $preset = $profile->renameFleetPreset($presetId, $name);
        $this->profiles->save($profile);

        return $preset;
    }
}
