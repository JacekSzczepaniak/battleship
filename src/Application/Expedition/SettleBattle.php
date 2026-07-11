<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Game\Game;
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
     * Rozlicza zakończoną bitwę: wynik i straty czytane ze stanu gry (nie od
     * klienta), XP i materiały wg definicji wyspy, idempotentnie (ponowne
     * wywołanie → zera). Zatopione statki: wygrana → do remontu, przegrana →
     * stracone.
     *
     * @return array{result:string, awarded:int, xp:int, rank:string, rankUp:bool,
     *     materialsAwarded:int, materials:int, lostShips:list<string>, damagedShips:list<string>}
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

        $outcome = $profile->settleBattle(
            $game->id(),
            $result,
            'won' === $result ? $island->xpWin : $island->xpLoss,
            'won' === $result ? $island->materialsWin : $island->materialsLoss,
            $this->sunkPlayerShipLengths($game),
        );
        $this->profiles->save($profile);

        return [
            'result' => $result,
            'awarded' => $outcome['xp'],
            'xp' => $profile->xp(),
            'rank' => $profile->rank()->value,
            'rankUp' => $outcome['xp'] > 0 && $profile->rank() !== $rankBefore,
            'materialsAwarded' => $outcome['materials'],
            'materials' => $profile->materials(),
            'lostShips' => $outcome['lost'],
            'damagedShips' => $outcome['damaged'],
        ];
    }

    /**
     * Długości statków gracza zatopionych w bitwie — liczone ze snapshotu gry:
     * statek jest zatopiony, gdy wszystkie jego pola zostały trafione przez AI.
     *
     * @return list<int>
     */
    private function sunkPlayerShipLengths(Game $game): array
    {
        $hits = [];
        foreach ($game->opponentShotsWithResults() as $shot) {
            if (in_array($shot['result'], ['hit', 'sunk'], true)) {
                $hits["{$shot['x']}:{$shot['y']}"] = true;
            }
        }

        $lengths = [];
        foreach ($game->fleet() ?? [] as $ship) {
            $allHit = true;
            foreach ($ship->cells() as $cell) {
                if (!isset($hits["{$cell->x}:{$cell->y}"])) {
                    $allHit = false;
                    break;
                }
            }
            if ($allHit) {
                $lengths[] = $ship->length;
            }
        }

        return $lengths;
    }
}
