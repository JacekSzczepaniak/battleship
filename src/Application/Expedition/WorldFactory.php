<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\WorldMap;

/** Buduje mapę świata kapitana: seed profilu + wyspy trasy z katalogu. */
final class WorldFactory
{
    public function __construct(private IslandCatalog $islands)
    {
    }

    public function worldFor(CaptainProfile $profile): WorldMap
    {
        return WorldMap::generate(
            $profile->worldSeed(),
            array_map(static fn ($island) => $island->id, $this->islands->all()),
        );
    }
}
