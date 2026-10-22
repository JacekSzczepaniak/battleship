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
     * (plansza z definicji wyspy, skład = wybrane albo domyślnie dobrane sprawne
     * statki w limicie pól wyspy — przeciwnik dostaje lustrzany skład) i rejestruje
     * bitwę w profilu. Dalej działa zwykły flow gry (fleet/shots). Na wyspie pod
     * banderą to obrona przed kontratakiem w toku — napastnik strzela pierwszy;
     * bez kontrataku wyspa pod banderą nie przyjmuje bitwy (#78).
     *
     * @param list<string>|null $shipIds skład bitewny; null = dobór domyślny
     */
    public function handle(string $profileId, string $islandId, ?array $shipIds = null): Game
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

        $defense = $profile->ownsIsland($island->id);
        if ($defense) {
            $profile->assertCanDefend($island->id);
        }

        if ([] === $profile->activeFleet()) {
            throw new \DomainException('No seaworthy ships');
        }
        $squad = $profile->battleSquad($shipIds, $island->squadCellLimit());

        $composition = [];
        foreach ($squad as $ship) {
            $length = $ship->type->length();
            $composition[$length] = ($composition[$length] ?? 0) + 1;
        }

        $game = $this->createGame->handle($island->boardWidth, $island->boardHeight, $island->mode, $composition, $defense ? 'opponent' : 'player');
        $shipIds = array_map(static fn ($s) => $s->id, $squad);
        $defense
            ? $profile->startDefense($island->id, $game->id(), $shipIds)
            : $profile->startBattle($island->id, $game->id(), $shipIds);
        $this->profiles->save($profile);

        return $game;
    }
}
