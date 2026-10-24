<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;

final class SellShip
{
    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Sprzedaż statku za złoto w porcie handlowym, przy którym flota stoi
     * (#140). Wartość (zwrot jak przy złomowaniu, przeliczony po kursie
     * sprzedaży akwenu) i blokady liczy domena (CaptainProfile::sellShip).
     *
     * @return array{gold: int, cargo: array{wood:int, steel:int, gold:int}, cargoCapacity: int, tick: int}
     */
    public function handle(string $profileId, string $portId, string $shipId): array
    {
        [$profile, $port] = TradeAtPort::portHere($this->profiles, $this->worldFactory, $profileId, $portId);

        $gold = $profile->sellShip($shipId, $port);
        $this->profiles->save($profile);

        return [
            'gold' => $gold,
            'cargo' => $profile->cargo()->toArray(),
            'cargoCapacity' => $profile->cargoCapacity(),
            'tick' => $profile->tick(),
        ];
    }
}
