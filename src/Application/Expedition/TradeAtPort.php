<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\MarketGood;
use App\Domain\Expedition\MarketSide;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\TradePort;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;

final class TradeAtPort
{
    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Kupno albo sprzedaż drewna lub stali za złoto na rynku portu handlowego,
     * przy którym flota STOI (#140). Kurs, spread, środki, ładownię i tik
     * liczy domena (CaptainProfile::trade).
     *
     * @return array{paid: array{wood:int, steel:int, gold:int}, received: array{wood:int, steel:int, gold:int},
     *     cargo: array{wood:int, steel:int, gold:int}, cargoCapacity: int, stockpile: array{wood:int, steel:int, gold:int}, tick: int}
     */
    public function handle(string $profileId, string $portId, MarketSide $side, MarketGood $good, int $gold): array
    {
        [$profile, $port] = self::portHere($this->profiles, $this->worldFactory, $profileId, $portId);

        $outcome = $profile->trade($port, $side, $good, $gold);
        $this->profiles->save($profile);

        return [
            'paid' => $outcome['paid']->toArray(),
            'received' => $outcome['received']->toArray(),
            'cargo' => $profile->cargo()->toArray(),
            'cargoCapacity' => $profile->cargoCapacity(),
            'stockpile' => $profile->stockpile($port->id)->toArray(),
            'tick' => $profile->tick(),
        ];
    }

    /**
     * Profil i port handlowy akwenu floty, pod warunkiem że flota stoi w porcie.
     *
     * @return array{0: CaptainProfile, 1: TradePort, 2: WorldMap}
     */
    public static function portHere(ProfileRepository $profiles, WorldFactory $worldFactory, string $profileId, string $portId): array
    {
        $profile = $profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }
        $port = $worldFactory->seaFor($profile)->tradePort;
        if (null === $port || $port->id !== $portId) {
            throw new \DomainException('Trade port not found');
        }
        $world = $worldFactory->worldFor($profile);
        if (!$profile->isAtTradePort($world)) {
            throw new \DomainException('Not at trade port');
        }

        return [$profile, $port, $world];
    }
}
