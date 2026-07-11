<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;

final class CreateProfile
{
    public function __construct(private ProfileRepository $profiles)
    {
    }

    public function handle(?string $name): CaptainProfile
    {
        $profile = CaptainProfile::create($name ?? 'Rozbitek');
        $this->profiles->save($profile);

        return $profile;
    }
}
