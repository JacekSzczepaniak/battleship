<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;

final class DeleteFleetPreset
{
    public function __construct(private ProfileRepository $profiles)
    {
    }

    /** Usuwa zestaw floty (#125). */
    public function handle(string $profileId, string $presetId): void
    {
        $profile = $this->profiles->get(new ProfileId($profileId)) ?? throw new \DomainException('Profile not found');

        $profile->deleteFleetPreset($presetId);
        $this->profiles->save($profile);
    }
}
