<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;

final class TradeWithMerchant
{
    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Handel na miejscu z handlarzem spotkanym w rejsie (#141): jedno
     * zlecenie sprzedaży i kupna drewna i stali za złoto z ładowni, po kursie
     * portu handlowego akwenu z marżą handlarza.
     *
     * @return array{goldReceived:int, goldPaid:int, cargo:array{wood:int, steel:int, gold:int}, tick:int}
     */
    public function handle(string $profileId, Resources $sell, Resources $buy): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $profile->ensureWorldState($this->worldFactory->worldFor($profile));
        // handlarz liczy od kursu portu handlowego akwenu (#140)
        $port = $this->worldFactory->seaFor($profile)->tradePort ?? throw new \DomainException('No market on this sea');
        $outcome = $profile->tradeWithMerchant($sell, $buy, $port->rate);
        $this->profiles->save($profile);

        return $outcome + ['cargo' => $profile->cargo()->toArray(), 'tick' => $profile->tick()];
    }
}
