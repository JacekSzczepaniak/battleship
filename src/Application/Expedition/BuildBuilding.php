<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;

final class BuildBuilding
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Stawia budynek osady na wyspie. Banderę, obecność kapitana, warunek
     * miejsca, jeden budynek typu i koszt egzekwuje domena
     * (CaptainProfile::buildBuilding).
     *
     * @return array{island: string, building: string, buildings: list<string>, stockpile: array{wood:int, steel:int, gold:int}, cargo: array{wood:int, steel:int, gold:int}}
     */
    public function handle(string $profileId, string $islandId, BuildingType $type): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $island = $this->islands->byId($islandId);
        if (null === $island) {
            throw new \DomainException('Island not found');
        }

        $profile->buildBuilding($type, $island, $this->worldFactory->worldFor($profile));
        $this->profiles->save($profile);

        return [
            'island' => $island->id,
            'building' => $type->value,
            'buildings' => array_map(static fn (BuildingType $t) => $t->value, $profile->buildingsOn($island->id)),
            'stockpile' => $profile->stockpile($island->id)->toArray(),
            'cargo' => $profile->cargo()->toArray(),
        ];
    }
}
