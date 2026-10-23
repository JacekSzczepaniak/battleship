<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\PrizeDecision;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;

final class DecidePrize
{
    public function __construct(private ProfileRepository $profiles)
    {
    }

    /**
     * Decyzja o statku zdobytym abordażem (#118): dołącz albo rozbierz.
     * Nie wymaga obecności przy wyspie i nie kosztuje tiku.
     *
     * @return array{decision:string, ship:?OwnedShip, refund:Resources, cargoLost:Resources, cargo:Resources}
     */
    public function handle(string $profileId, string $prizeId, PrizeDecision $decision): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $outcome = $profile->decidePrize($prizeId, $decision);
        $this->profiles->save($profile);

        return [
            'decision' => $outcome['decision'],
            'ship' => $outcome['ship'],
            'refund' => $outcome['refund'],
            'cargoLost' => $outcome['cargoLost'],
            'cargo' => $profile->cargo(),
        ];
    }
}
