<?php

namespace App\Application\Expedition;

use App\Application\Game\CreateGame;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Game\Game;
use App\Domain\Game\Weapon\SonarSpec;
use App\Domain\Shared\ProfileId;

final class StartGuardianBattle
{
    public function __construct(
        private ProfileRepository $profiles,
        private SeaGraph $seas,
        private CreateGame $createGame,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Rozpoczyna bitwę ze strażnikiem strzeżonej cieśniny (#107): brama musi
     * leżeć na akwenie floty, flota stoi na jej sektorze, ranga sięga progu
     * strażnika, a brama jest jeszcze zamknięta. Skład gracza jak przy wyspie
     * (limit 20% pól planszy strażnika); wróg płynie stałą flotą strażnika
     * z domyślnym wyposażeniem — bez lustra składu i wariantów gracza.
     *
     * @param list<string>|null $shipIds skład bitewny; null = dobór domyślny
     */
    public function handle(string $profileId, string $gateId, ?array $shipIds = null): Game
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $passage = $this->seas->passage($profile->currentSea(), $gateId)
            ?? throw new \DomainException('Gate not found');
        $guardian = $passage->gate->guardian()
            ?? throw new \DomainException('Gate has no guardian');

        if ($profile->isGateOpen($gateId)) {
            throw new \DomainException('Gate already open');
        }
        if (!$profile->rank()->atLeast($guardian->requiredRank)) {
            throw new \DomainException(sprintf('Gate locked: requires rank %s', $guardian->requiredRank->value));
        }
        if (!$profile->isAtGate($this->worldFactory->worldFor($profile), $gateId)) {
            throw new \DomainException('Not at gate');
        }

        if ([] === $profile->activeFleet()) {
            throw new \DomainException('No seaworthy ships');
        }
        $squad = $profile->battleSquad($shipIds, $guardian->squadCellLimit());
        [$composition, $variants] = SquadComposition::of($squad);

        $sonarRadius = $profile->hasResearched(Technology::SonarDalekiegoZasiegu) ? SonarSpec::LONG_RANGE_RADIUS : null;

        $game = $this->createGame->handle(
            $guardian->boardWidth,
            $guardian->boardHeight,
            $guardian->mode,
            $composition,
            'player',
            $guardian->composition(),
            $variants ?: null,
            $sonarRadius,
            opponentVariants: [],
        );
        $profile->startGuardianBattle($gateId, $game->id(), array_map(static fn ($s) => $s->id, $squad));
        $this->profiles->save($profile);

        return $game;
    }
}
