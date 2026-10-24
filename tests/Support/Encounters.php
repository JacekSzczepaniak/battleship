<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\EncounterDecision;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

/**
 * Spotkania na morzu (#141) w testach, które sprawdzają coś innego niż
 * spotkania. Seed profilu z API jest losowy, więc rejs trafia na handlarza
 * albo pirata z szansą 8% i zatrzymuje dalszą żeglugę.
 */
final class Encounters
{
    /**
     * Zdejmuje oczekujące spotkanie z zapisanego profilu bez skutków w grze
     * (tik, ładunek i złoto bez zmian). Zostaje tylko wpis w dzienniku.
     */
    public static function dismiss(ProfileRepository $profiles, string $profileId): void
    {
        $profile = $profiles->get(new ProfileId($profileId));
        if (null === $profile || null === $profile->encounter()) {
            return;
        }

        $mapper = new ProfileSnapshotMapper();
        $state = $mapper->toArray($profile);
        unset($state['encounter']);
        $profiles->save($mapper->toDomain($profileId, $state));
    }

    /**
     * Rozstrzyga oczekujące spotkanie na profilu w pamięci: handlarza mija,
     * przed piratem ucieka (tik i 20% ładunku).
     */
    public static function evade(CaptainProfile $profile): void
    {
        $encounter = $profile->encounter();
        if (null !== $encounter) {
            $profile->decideEncounter(EncounterType::Merchant === $encounter->type ? EncounterDecision::Pass : EncounterDecision::Flee);
        }
    }
}
