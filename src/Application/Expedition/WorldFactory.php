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

    /**
     * Mapa dowolnego akwenu dla kapitana (np. akwenu docelowego bramy), ze
     * znajomością pozostałych akwenów: kontrataki i ich rozstrzygnięcia
     * obejmują cały świat (#109).
     */
    public function mapOf(Sea $sea, CaptainProfile $profile): WorldMap
    {
        $others = array_map(
            fn (Sea $other) => $this->bareMapOf($other, $profile),
            array_values(array_filter($this->seas->seas(), static fn (Sea $other) => $other->id !== $sea->id)),
        );

        return $this->bareMapOf($sea, $profile)->withOtherSeas(...$others);
    }

    private function bareMapOf(Sea $sea, CaptainProfile $profile): WorldMap
    {
        return $sea->map($profile->worldSeed(), $this->seas->passagesFrom($sea->id));
    }
}
