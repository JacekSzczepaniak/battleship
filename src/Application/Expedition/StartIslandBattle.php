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
    ) {
    }

    /**
     * Rozpoczyna bitwę o wyspę: waliduje rangę, tworzy grę wg zasad wyspy
     * i rejestruje bitwę w profilu. Dalej działa zwykły flow gry (fleet/shots).
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

        $game = $this->createGame->handle(null, null, $island->mode);
        $profile->startBattle($island->id, $game->id());
        $this->profiles->save($profile);

        return $game;
    }
}
