<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;

final class ScrapShip
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Złomuje statek w stoczni wyspy, na której kapitan stoi (#124). Wystarczy
     * stocznia dowolnego poziomu; zwrot i blokady (bitwa, ostatnia sprawna
     * jednostka) liczy domena (CaptainProfile::scrapShip).
     *
     * @return array{refund: Resources, stockpile: Resources, cargo: Resources}
     */
    public function handle(string $profileId, string $islandId, string $shipId): array
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
        if ($profile->shipyardLevel($island) < 1) {
            throw new \DomainException('No shipyard on this island');
        }

        $refund = $profile->scrapShip($shipId, $island->id);
        $this->profiles->save($profile);

        return ['refund' => $refund, 'stockpile' => $profile->stockpile($island->id), 'cargo' => $profile->cargo()];
    }
}
