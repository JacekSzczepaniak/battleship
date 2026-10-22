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
        private WorldFactory $worldFactory,
    ) {
    }

    /**
     * Rozlicza zakończoną bitwę: wynik i straty czytane ze stanu gry (nie od
     * klienta), XP i surowce wg definicji wyspy, idempotentnie (ponowne
     * wywołanie → zera). Pierwsza wygrana o wyspę stawia banderę. Zatopione
     * statki: wygrana → do remontu, przegrana → stracone.
     *
     * @return array{result:string, awarded:int, xp:int, rank:string, rankUp:bool,
     *     loot:array{wood:int, steel:int, gold:int}, stockpile:array{wood:int, steel:int, gold:int},
     *     cargo:array{wood:int, steel:int, gold:int}, cargoLost:array{wood:int, steel:int, gold:int},
     *     lostShips:list<string>, damagedShips:list<string>, captured:bool,
     *     depositStarted:array{wood:int, steel:int, gold:int}|null}
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
        // urobek złóż do bieżącego tiku liczy się przed łupem, który trafia do tego samego magazynu
        $profile->ensureWorldState($this->worldFactory->worldFor($profile));

        $outcome = $profile->settleBattle(
            $game->id(),
            $result,
            'won' === $result ? $island->xpWin : $island->xpLoss,
            'won' === $result ? $island->lootWin : $island->lootLoss,
            $this->sunkPlayerShipLengths($game),
            $this->sunkEnemyShipCount($game),
        );
        $this->profiles->save($profile);

        return [
            'result' => $result,
            'awarded' => $outcome['xp'],
            'xp' => $profile->xp(),
            'rank' => $profile->rank()->value,
            'rankUp' => $outcome['xp'] > 0 && $profile->rank() !== $rankBefore,
            'loot' => $outcome['loot']->toArray(),
            // łup leży w magazynie wyspy bitwy; ładunek ponad pojemność po stratach przepadł
            'stockpile' => $profile->stockpile($islandId)->toArray(),
            'cargo' => $profile->cargo()->toArray(),
            'cargoLost' => $outcome['cargoLost']->toArray(),
            'lostShips' => $outcome['lost'],
            'damagedShips' => $outcome['damaged'],
            // wygrana postawiła banderę na wyspie
            'captured' => $outcome['captured'],
            // zdobycie wyspy ze złożem uruchamia wydobycie: urobek na tik
            'depositStarted' => $outcome['captured'] && $island->hasDeposit() ? $island->depositYield->toArray() : null,
        ];
    }

    /**
     * Liczba statków wroga zatopionych przez gracza — ze snapshotu gry, tak jak
     * straty własne: statek jest zatopiony, gdy wszystkie jego pola trafił gracz.
     */
    private function sunkEnemyShipCount(Game $game): int
    {
        $hits = [];
        foreach ($game->shotsWithResults() as $shot) {
            if (in_array($shot['result'], ['hit', 'sunk'], true)) {
                $hits["{$shot['x']}:{$shot['y']}"] = true;
            }
        }

        $count = 0;
        foreach ($game->opponentFleet() ?? [] as $ship) {
            $allHit = true;
            foreach ($ship->cells() as $cell) {
                if (!isset($hits["{$cell->x}:{$cell->y}"])) {
                    $allHit = false;
                    break;
                }
            }
            if ($allHit) {
                ++$count;
            }
        }

        return $count;
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
