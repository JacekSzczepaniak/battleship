<?php

namespace App\Application\Expedition;

use App\Domain\Expedition\BattleKind;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Game\Game;
use App\Domain\Game\GameRepository;
use App\Domain\Game\GameStatus;
use App\Domain\Game\Ship;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;

final class SettleBattle
{
    public function __construct(
        private ProfileRepository $profiles,
        private GameRepository $games,
        private IslandCatalog $islands,
        private WorldFactory $worldFactory,
        private SeaGraph $seas,
    ) {
    }

    /**
     * Rozlicza zakończoną bitwę: wynik i straty czytane ze stanu gry (nie od
     * klienta), XP i surowce wg definicji wyspy, idempotentnie (ponowne
     * wywołanie → zera). Pierwsza wygrana o wyspę stawia banderę; obrona
     * rozstrzyga kontratak (przegrana odbiera wyspę i połowę magazynu, #78).
     * Bitwa ze strażnikiem płaci wg strażnika bramy, a wygrana otwiera bramę;
     * łup ląduje w ładowni (#107). Udany abordaż zostawia zdobyty statek do
     * decyzji kapitana (#118). Zatopione statki: wygrana → do remontu,
     * przegrana → stracone.
     *
     * @return array{result:string, awarded:int, xp:int, rank:string, rankUp:bool,
     *     loot:array{wood:int, steel:int, gold:int},
     *     stockpile:array{wood:int, steel:int, gold:int}|null,
     *     cargo:array{wood:int, steel:int, gold:int}, cargoLost:array{wood:int, steel:int, gold:int},
     *     lostShips:list<string>, damagedShips:list<string>, captured:bool,
     *     depositStarted:array{wood:int, steel:int, gold:int}|null,
     *     defense:array{held:bool, stockpileLost:array{wood:int, steel:int, gold:int}}|null, gateOpened:bool,
     *     prize:array{id:string, type:string, variant:?string, tick:int}|null}
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

        // bitwa ze strażnikiem: w rejestrze zamiast wyspy stoi brama
        $guardian = null;
        $island = null;
        if (BattleKind::Guardian === $profile->battleKindFor($game->id())) {
            $guardian = $this->seas->gate($islandId)?->guardian() ?? throw new \DomainException('Gate not found');
        } else {
            $island = $this->islands->byId($islandId) ?? throw new \DomainException('Island not found');
        }
        $rewards = $guardian ?? $island;

        if (!$game->isFinished()) {
            throw new \DomainException('Battle not finished yet');
        }

        $result = GameStatus::Lost === $game->status() ? 'lost' : 'won';
        $rankBefore = $profile->rank();
        // urobek złóż do bieżącego tiku liczy się przed łupem, który trafia do tego samego magazynu
        $world = $this->worldFactory->worldFor($profile);
        $profile->ensureWorldState($world);

        // wariant zatopionego statku (#99) wskazuje, który statek floty poniósł stratę
        $sunk = $this->sunkPlayerShips($game);
        $sunkVariants = array_filter(array_map(
            static fn (Ship $ship) => ShipVariant::ofBattleShip($ship->length, $ship->loadout),
            $sunk,
        ));
        // abordaż (#118): zdobyty statek wroga, typ z długości, wariant z wyposażenia
        $boarded = $game->boardedShip();
        $boardedType = null !== $boarded ? ShipType::ofLength($boarded->length) : null;
        $outcome = $profile->settleBattle(
            $game->id(),
            $result,
            'won' === $result ? $rewards->xpWin : $rewards->xpLoss,
            'won' === $result ? $rewards->lootWin : $rewards->lootLoss,
            array_map(static fn (Ship $ship) => $ship->length, $sunk),
            $this->sunkEnemyShipCount($game),
            $sunkVariants,
            $boardedType,
            null !== $boarded && null !== $boardedType ? ShipVariant::ofBattleShip($boarded->length, $boarded->loadout) : null,
        );
        $this->profiles->save($profile);

        return [
            'result' => $result,
            'awarded' => $outcome['xp'],
            'xp' => $profile->xp(),
            'rank' => $profile->rank()->value,
            'rankUp' => $outcome['xp'] > 0 && $profile->rank() !== $rankBefore,
            'loot' => $outcome['loot']->toArray(),
            // łup leży w magazynie wyspy bitwy (strażnik: w ładowni, null); ładunek ponad pojemność po stratach przepadł
            'stockpile' => null !== $island ? $profile->stockpile($islandId)->toArray() : null,
            'cargo' => $profile->cargo()->toArray(),
            'cargoLost' => $outcome['cargoLost']->toArray(),
            'lostShips' => $outcome['lost'],
            'damagedShips' => $outcome['damaged'],
            // wygrana postawiła banderę na wyspie
            'captured' => $outcome['captured'],
            // zdobycie wyspy ze złożem uruchamia wydobycie: urobek na tik (odbita wyspa z tartakiem — podwojony)
            'depositStarted' => $outcome['captured'] && null !== $island && $island->hasDeposit() ? $profile->depositYield($island, $world->theme())->toArray() : null,
            // obrona przed kontratakiem: czy bandera została i co przepadło z magazynu
            'defense' => null !== $outcome['defense']
                ? ['held' => $outcome['defense']['held'], 'stockpileLost' => $outcome['defense']['stockpileLost']->toArray()]
                : null,
            // wygrana ze strażnikiem otworzyła bramę akwenu na stałe
            'gateOpened' => $outcome['gateOpened'],
            // abordaż (#118): zdobyty statek czeka w profilu na decyzję; null = bitwa bez udanego abordażu
            'prize' => $outcome['prize']?->toArray(),
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
     * Statki gracza zatopione w bitwie — liczone ze snapshotu gry: statek
     * jest zatopiony, gdy wszystkie jego pola zostały trafione przez AI.
     *
     * @return list<Ship>
     */
    private function sunkPlayerShips(Game $game): array
    {
        $hits = [];
        foreach ($game->opponentShotsWithResults() as $shot) {
            if (in_array($shot['result'], ['hit', 'sunk'], true)) {
                $hits["{$shot['x']}:{$shot['y']}"] = true;
            }
        }

        $sunk = [];
        foreach ($game->fleet() ?? [] as $ship) {
            $allHit = true;
            foreach ($ship->cells() as $cell) {
                if (!isset($hits["{$cell->x}:{$cell->y}"])) {
                    $allHit = false;
                    break;
                }
            }
            if ($allHit) {
                $sunk[] = $ship;
            }
        }

        return $sunk;
    }
}
