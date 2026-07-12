<?php

namespace App\Application\Expedition;

use App\Application\Game\CreateGame;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Game\Game;
use App\Domain\Shared\ProfileId;

final class StartIslandBattle
{
    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private CreateGame $createGame,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Rozpoczyna bitwę o wyspę: waliduje rangę, tworzy grę wg zasad wyspy
     * (plansza z definicji wyspy, skład floty = sprawne statki kapitana —
     * przeciwnik dostaje lustrzany skład) i rejestruje bitwę w profilu.
     * Dalej działa zwykły flow gry (fleet/shots).
     */
    public function handle(string $profileId, string $islandId): Game
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $island = $this->islands->byId($islandId);
        if (null === $island) {
            throw new \DomainException('Island not found');
        }

        if (!$island->isAccessibleFor($profile->rank())) {
            throw new \DomainException(sprintf('Island locked: requires rank %s', $island->requiredRank->value));
        }

        // wolne morze: bitwę toczy się tam, gdzie stoi okręt flagowy
        if (!$profile->isAt($this->worldFactory->worldFor($profile), $island->id)) {
            throw new \DomainException('Not at island');
        }

        $activeFleet = $profile->activeFleet();
        if ([] === $activeFleet) {
            throw new \DomainException('No seaworthy ships');
        }

        $composition = [];
        foreach ($activeFleet as $ship) {
            $length = $ship->type->length();
            $composition[$length] = ($composition[$length] ?? 0) + 1;
        }

        $game = $this->createGame->handle($island->boardWidth, $island->boardHeight, $island->mode, $composition);
        $profile->startBattle($island->id, $game->id(), array_map(static fn ($s) => $s->id, $activeFleet));
        $this->profiles->save($profile);

        return $game;
    }
}
