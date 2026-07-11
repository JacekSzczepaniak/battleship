<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Game\GameRepository;
use App\Domain\Game\GameStatus;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;

final class SettleBattle
{
    public function __construct(
        private ProfileRepository $profiles,
        private GameRepository $games,
        private IslandCatalog $islands,
    ) {
    }

    /**
     * Rozlicza zakończoną bitwę: wynik czytany ze stanu gry (nie od klienta),
     * XP wg definicji wyspy, idempotentnie (ponowne wywołanie → awarded 0).
     *
     * @return array{result:string, awarded:int, xp:int, rank:string, rankUp:bool}
     */
    public function handle(string $profileId, string $gameId): array
    {
        $profile = $this->profiles->get(new ProfileId($profileId));
        if (null === $profile) {
            throw new \DomainException('Profile not found');
        }

        $game = $this->games->get(new GameId($gameId));
        if (null === $game) {
            throw new \DomainException('Game not found');
        }

        $islandId = $profile->islandFor($game->id());
        if (null === $islandId) {
            throw new \DomainException('Battle not registered for this profile');
        }

        $island = $this->islands->byId($islandId);
        if (null === $island) {
            throw new \DomainException('Island not found');
        }

        if (!$game->isFinished()) {
            throw new \DomainException('Battle not finished yet');
        }

        $result = GameStatus::Lost === $game->status() ? 'lost' : 'won';
        $rankBefore = $profile->rank();

        $awarded = $profile->settleBattle(
            $game->id(),
            $result,
            'won' === $result ? $island->xpWin : $island->xpLoss,
        );
        $this->profiles->save($profile);

        return [
            'result' => $result,
            'awarded' => $awarded,
            'xp' => $profile->xp(),
            'rank' => $profile->rank()->value,
            'rankUp' => $awarded > 0 && $profile->rank() !== $rankBefore,
        ];
    }
}
