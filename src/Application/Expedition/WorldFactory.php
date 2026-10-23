<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\WorldMap;

/** Buduje mapę akwenu, w którym stoi flota kapitana: seed profilu + wyspy, złoża, motyw i bramy akwenu z grafu świata. */
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
        return $this->mapOf($this->seaFor($profile), $profile);
    }

    /** Mapa dowolnego akwenu dla kapitana (np. akwenu docelowego bramy). */
    public function mapOf(Sea $sea, CaptainProfile $profile): WorldMap
    {
        return $sea->map($profile->worldSeed(), $this->seas->passagesFrom($sea->id));
    }
}
