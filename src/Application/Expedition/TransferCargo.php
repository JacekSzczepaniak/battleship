<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;

final class TransferCargo
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Załadunek (magazyn wyspy → ładownia) albo rozładunek (ładownia → magazyn)
     * w porcie wyspy, na której kapitan STOI. Każda operacja to dokowanie
     * i kosztuje tik; pojemność i stany egzekwuje domena.
     *
     * @return array{cargo: array{wood:int, steel:int, gold:int}, cargoCapacity: int,
     *     stockpile: array{wood:int, steel:int, gold:int}, tick: int}
     */
    public function handle(string $profileId, string $islandId, string $direction, Resources $amount): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }
        $island = $this->islands->byId($islandId);
        if (null === $island) {
            throw new \DomainException('Island not found');
        }
        if (!$profile->isAt($this->worldFactory->worldFor($profile), $island->id)) {
            throw new \DomainException('Not at island');
        }

        match ($direction) {
            'load' => $profile->loadCargo($island->id, $amount),
            'unload' => $profile->unloadCargo($island->id, $amount),
            default => throw new \InvalidArgumentException('Direction must be load|unload'),
        };
        $this->profiles->save($profile);

        return [
            'cargo' => $profile->cargo()->toArray(),
            'cargoCapacity' => $profile->cargoCapacity(),
            'stockpile' => $profile->stockpile($island->id)->toArray(),
            'tick' => $profile->tick(),
        ];
    }
}
