<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;

final class BuySeaChart
{
    public function __construct(
        private ProfileRepository $profiles,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Kupno mapy akwenu na rynku portu handlowego, przy którym flota stoi
     * (#143). Ofertę, cenę, środki i tik liczy domena (CaptainProfile::buySeaChart).
     *
     * @return array{sea: string, gold: int, cargo: array{wood:int, steel:int, gold:int}, stockpile: array{wood:int, steel:int, gold:int}, tick: int}
     */
    public function handle(string $profileId, string $portId): array
    {
        [$profile, $port] = TradeAtPort::portHere($this->profiles, $this->worldFactory, $profileId, $portId);

        $gold = $profile->buySeaChart($port);
        $this->profiles->save($profile);

        return [
            'sea' => $port->chart->seaId ?? '',
            'gold' => $gold,
            'cargo' => $profile->cargo()->toArray(),
            'stockpile' => $profile->stockpile($port->id)->toArray(),
            'tick' => $profile->tick(),
        ];
    }
}
