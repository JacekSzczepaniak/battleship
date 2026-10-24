<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\EncounterDecision;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;

final class ResolveEncounter
{
    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Decyzja przy spotkaniu bez bitwy (#141): minięcie handlarza, ucieczka
     * przed piratem (tik i część ładunku) albo okup w złocie z ładowni.
     *
     * @return array{decision:string, cargoLost:array{wood:int, steel:int, gold:int}, goldPaid:int, cargo:array{wood:int, steel:int, gold:int}, tick:int}
     */
    public function handle(string $profileId, EncounterDecision $decision): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $profile->ensureWorldState($this->worldFactory->worldFor($profile));
        $outcome = $profile->decideEncounter($decision);
        $this->profiles->save($profile);

        return [
            'decision' => $decision->value,
            'cargoLost' => $outcome['cargoLost']->toArray(),
            'goldPaid' => $outcome['goldPaid'],
            'cargo' => $profile->cargo()->toArray(),
            'tick' => $profile->tick(),
        ];
    }
}
