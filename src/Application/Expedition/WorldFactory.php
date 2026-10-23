<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\WorldMap;

/** Buduje mapę akwenu, w którym stoi flota kapitana: seed profilu + wyspy, złoża i motyw akwenu z grafu świata. */
final class WorldFactory
{
    public function __construct(private SeaGraph $seas)
    {
    }

    /** Akwen, w którym stoi flota. */
    public function seaFor(CaptainProfile $profile): Sea
    {
        return $this->seas->sea($profile->currentSea())
            ?? throw new \LogicException("Unknown sea {$profile->currentSea()}");
    }

    public function worldFor(CaptainProfile $profile): WorldMap
    {
        return $this->seaFor($profile)->map($profile->worldSeed());
    }
}
