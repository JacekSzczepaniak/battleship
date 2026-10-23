<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Shared\ProfileId;

final class PassGate
{
    public function __construct(
        private ProfileRepository $profiles,
        private SeaGraph $seas,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Przejście przez otwartą bramę akwenu floty (#107): z sektora bramy na
     * sektor tej samej bramy w akwenie docelowym, za 1 tik. Wpłynięcie liczy
     * domena jak rejs (sztorm, konwój, kartografia).
     *
     * @return array<string,mixed>
     */
    public function handle(string $profileId, string $gateId): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $passage = $this->seas->passage($profile->currentSea(), $gateId)
            ?? throw new \DomainException('Gate not found');
        $target = $this->seas->sea($passage->to)
            ?? throw new \LogicException("Unknown sea {$passage->to}");

        $from = $this->worldFactory->worldFor($profile);
        $to = $this->worldFactory->mapOf($target, $profile);
        $outcome = $profile->passGate($passage, $from, $to);
        $this->profiles->save($profile);

        $position = $profile->position();

        return [
            'sea' => ['id' => $target->id, 'name' => $target->name, 'theme' => $target->theme->toArray()],
            'position' => $position,
            'discoveredNow' => $outcome['discoveredNow'],
            'cartography' => $outcome['cartography'],
            'cartographyLost' => $outcome['cartographyLost'],
            'event' => $outcome['event'],
            'cargo' => $profile->cargo()->toArray(),
            'cargoCapacity' => $profile->cargoCapacity(),
            'atIsland' => $to->islandAt($position['x'], $position['y']),
            'atGate' => $to->gateAt($position['x'], $position['y']),
            'tick' => $profile->tick(),
        ];
    }
}
