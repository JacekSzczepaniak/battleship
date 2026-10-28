<?php

namespace App\Application\Expedition;

use App\Application\Game\CreateGame;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Technology;
use App\Domain\Game\Game;
use App\Domain\Game\Siege\FortRules;
use App\Domain\Game\Weapon\SonarSpec;
use App\Domain\Shared\ProfileId;

final class StartSiege
{
    /** Skład oblężenia mieści się w 20% planszy fortu, jak każdy skład bitewny (#73). */
    public const SQUAD_CELL_LIMIT = FortRules::SIZE * FortRules::SIZE * 20 / 100;

    public function __construct(
        private ProfileRepository $profiles,
        private IslandCatalog $islands,
        private CreateGame $createGame,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Rozpoczyna oblężenie fortu oblężonej twierdzy (#120): flota musi stać
     * przy wyspie, a potyczka z flotą strażniczą musi być wygrana. Gra toczy
     * się na planszy fortu (#119), którego układ wynika z seeda świata, wyspy
     * i numeru próby — każda próba to nowy fort. Fort nie jest statkiem, więc
     * abordażu w oblężeniu nie ma. Technologie Oblężnictwa (#177) działają
     * tylko tutaj.
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
        if (!$island->stronghold) {
            throw new \DomainException('Island has no fort');
        }

        $world = $this->worldFactory->worldFor($profile);
        if (!$profile->isAt($world, $island->id)) {
            throw new \DomainException('Not at island');
        }
        if ($profile->ownsIsland($island->id)) {
            throw new \DomainException('Island already captured');
        }
        if (!$profile->isBesieged($island->id)) {
            throw new \DomainException('Island not besieged');
        }

        if ([] === $profile->activeFleet()) {
            throw new \DomainException('No seaworthy ships');
        }
        $squad = $profile->battleSquad($shipIds, self::SQUAD_CELL_LIMIT);
        [$composition, $variants] = SquadComposition::of($squad);

        $sonarRadius = $profile->hasResearched(Technology::SonarDalekiegoZasiegu) ? SonarSpec::LONG_RANGE_RADIUS : null;
        $attempt = $profile->siegeAttempts($island->id) + 1;
        $fortSeed = crc32("{$profile->worldSeed()}:{$island->id}:fort:{$attempt}") & 0x7FFFFFFF;

        $game = $this->createGame->handle(null, null, 'fun', $composition, 'player', null, $variants ?: null, $sonarRadius, fortSeed: $fortSeed, perks: CaptainBattlePerks::of($profile), siegeTech: CaptainSiegeTech::of($profile));
        $profile->startSiege($island->id, $game->id(), array_map(static fn ($s) => $s->id, $squad));
        $this->profiles->save($profile);

        return $game;
    }
}
