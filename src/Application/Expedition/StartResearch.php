<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;

final class StartResearch
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Zaczyna badanie w uczelni wyspy. Czynną uczelnię, obecność kapitana,
     * jedno badanie naraz, regułę drzewka i koszt egzekwuje domena
     * (CaptainProfile::startResearch).
     *
     * @return array{island: string, technology: string, ticksLeft: int, stockpile: array{wood:int, steel:int, gold:int}, cargo: array{wood:int, steel:int, gold:int}}
     */
    public function handle(string $profileId, string $islandId, Technology $technology): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $island = $this->islands->byId($islandId);
        if (null === $island) {
            throw new \DomainException('Island not found');
        }

        $profile->startResearch($technology, $island, $this->worldFactory->worldFor($profile));
        $this->profiles->save($profile);

        return [
            'island' => $island->id,
            'technology' => $technology->value,
            'ticksLeft' => $profile->researchTicksLeft() ?? 0,
            'stockpile' => $profile->stockpile($island->id)->toArray(),
            'cargo' => $profile->cargo()->toArray(),
        ];
    }
}
