<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;

final class SetFieldRepairs
{
    public function __construct(private ProfileRepository $profiles)
    {
    }

    /** Włącza albo wyłącza naprawy kutrów remontowych poza stocznią (#152). Nie kosztuje tiku. */
    public function handle(string $profileId, bool $enabled): void
    {
        $profile = $this->profiles->get(new ProfileId($profileId)) ?? throw new \DomainException('Profile not found');

        $profile->setFieldRepairs($enabled);
        $this->profiles->save($profile);
    }
}
