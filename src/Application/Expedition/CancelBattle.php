<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\ProfileRepository;
use App\Domain\Game\GameRepository;
use App\Domain\Game\GameStatus;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;

final class CancelBattle
{
    public function __construct(
        private ProfileRepository $profiles,
        private GameRepository $games,
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * „Zmień skład” (#166): anuluje bitwę wyprawy, której flota nie została
     * jeszcze rozstawiona — przed rozstawieniem nikt nie strzelił. Profil
     * zdejmuje bitwę z rejestru (tik przepada), a gra znika. Rozstawiona
     * bitwa toczy się do końca.
     *
     * @return array{ships: list<string>}
     */
    public function handle(string $profileId, string $gameId): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $id = new GameId($gameId);
        $game = $this->games->get($id);
        if (null === $game) {
            throw new \DomainException('Game not found');
        }
        if (null === $profile->islandFor($id)) {
            throw new \DomainException('Battle not registered for this profile');
        }
        if (GameStatus::Pending !== $game->status()) {
            throw new \DomainException('Battle already started');
        }

        // kontratak z minionym terminem rozstrzyga się przy anulowaniu, więc świat musi być aktualny
        $world = $this->worldFactory->worldFor($profile);
        $profile->ensureWorldState($world);
        $ships = $profile->cancelBattle($id, $world);
        $this->profiles->save($profile);
        $this->games->remove($id);

        return ['ships' => $ships];
    }
}
