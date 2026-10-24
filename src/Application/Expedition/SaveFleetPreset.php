<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\FleetPreset;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Game\Orientation;
use App\Domain\Shared\ProfileId;

final class SaveFleetPreset
{
    public function __construct(private ProfileRepository $profiles)
    {
    }

    /**
     * Zapisuje zestaw floty z rozmieszczeniem (#125); zestaw o tej samej nazwie
     * jest nadpisywany. Zasady (flota, plansza, limit) egzekwuje domena.
     *
     * @param list<array{shipId: string, x: int, y: int, o: Orientation}> $placements
     *
     * @return array{preset: FleetPreset, created: bool} created = nowy zestaw, nie nadpisanie
     */
    public function handle(string $profileId, string $name, int $boardWidth, int $boardHeight, array $placements): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId)) ?? throw new \DomainException('Profile not found');

        $count = count($profile->fleetPresets());
        $preset = $profile->saveFleetPreset($name, $boardWidth, $boardHeight, $placements);
        $this->profiles->save($profile);

        return ['preset' => $preset, 'created' => count($profile->fleetPresets()) > $count];
    }
}
