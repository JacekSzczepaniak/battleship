<?php

namespace App\Application\Expedition;

use App\Application\Game\CreateGame;
use App\Domain\Expedition\EncounterCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Technology;
use App\Domain\Game\Game;
use App\Domain\Game\Weapon\SonarSpec;
use App\Domain\Shared\ProfileId;

final class StartEncounterBattle
{
    public function __construct(
        private ProfileRepository $profiles,
        private CreateGame $createGame,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Bitwa spotkania na otwartym morzu (#141): napad na handlarza albo
     * walka z piratem. Wróg płynie stałą flotą akwenu, bez lustra składu
     * i wariantów gracza (jak strażnik, #107). Skład gracza w limicie 20%
     * pól planszy; abordaż dostępny jak w każdej bitwie wyprawy (D2 Epica H).
     *
     * @param list<string>|null $shipIds skład bitewny; null = dobór domyślny
     */
    public function handle(string $profileId, ?array $shipIds = null): Game
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $profile->ensureWorldState($this->worldFactory->worldFor($profile));
        $encounter = $profile->encounter() ?? throw new \DomainException('No encounter pending');
        $enemy = EncounterCatalog::fleet($encounter->type, $encounter->sea);

        if ([] === $profile->activeFleet()) {
            throw new \DomainException('No seaworthy ships');
        }
        $squad = $profile->battleSquad($shipIds, $enemy->squadCellLimit());
        [$composition, $variants] = SquadComposition::of($squad);

        $sonarRadius = $profile->hasResearched(Technology::SonarDalekiegoZasiegu) ? SonarSpec::LONG_RANGE_RADIUS : null;

        $game = $this->createGame->handle(
            $enemy->boardWidth,
            $enemy->boardHeight,
            $enemy->mode,
            $composition,
            'player',
            $enemy->composition(),
            $variants ?: null,
            $sonarRadius,
            opponentVariants: [],
            boarding: true,
            perks: CaptainBattlePerks::of($profile),
        );
        $profile->startEncounterBattle($game->id(), array_map(static fn ($s) => $s->id, $squad));
        $this->profiles->save($profile);

        return $game;
    }
}
