<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;

final class CreateProfile
{
    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
    ) {
    }

    public function handle(?string $name): CaptainProfile
    {
        $profile = CaptainProfile::create($name ?? 'Rozbitek');
        // drewno startowe od razu ląduje w magazynie wyspy startowej
        $profile->ensureWorldState($this->worldFactory->worldFor($profile));
        $this->profiles->save($profile);

        return $profile;
    }
}
