<?php

namespace App\Domain\Expedition;

use App\Domain\Shared\ProfileId;

interface ProfileRepository
{
    public function save(CaptainProfile $profile): void;

    public function get(ProfileId $id): ?CaptainProfile;
}
