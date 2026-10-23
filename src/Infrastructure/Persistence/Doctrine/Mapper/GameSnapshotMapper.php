<?php

namespace App\Infrastructure\Persistence\Doctrine\Mapper;

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FleetComposition;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\GameStatus;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ruleset;
use App\Domain\Game\Ship;
use App\Domain\Game\Siege\Fort;
use App\Domain\Game\Weapon\Loadout;
use App\Domain\Game\Weapon\SonarSpec;
use App\Domain\Shared\GameId;

final class GameSnapshotMapper
{
    public function toArray(Game $game): array
    {
        $size = $game->ruleset()->boardSize();

        $fleet = null;
        if (null !== $game->fleet()) {
            $fleet = array_map($this->shipToArray(...), $game->fleet());
        }

        // opponent fleet (optional)
        $opponentFleet = null;
        if (null !== $game->opponentFleet()) {
            $opponentFleet = array_map($this->shipToArray(...), $game->opponentFleet());
        }

        // shots with results
        $shots = array_map(
            static fn (array $s) => ['x' => $s['x'], 'y' => $s['y'], 'r' => $s['result']],
            $game->shotsWithResults()
        );

        return [
            'status' => $game->status()->value,
            'ruleset' => [
                'type' => $game->ruleset()->name(),
                'board' => ['w' => $size->width, 'h' => $size->height],
                'ships' => $game->ruleset()->allowedShips(),
                // skład przeciwnika tylko, gdy nie jest lustrzany (bonus pełnej bandery, #79)
                'opponentShips' => $game->ruleset()->opponentShips() !== $game->ruleset()->allowedShips() ? $game->ruleset()->opponentShips() : null,
                // warianty wyposażenia (#95) tylko, gdy są; wroga tylko, gdy nie są lustrzane
                'variants' => $this->variantsOrNull($game->ruleset()->variants()),
                'opponentVariants' => $this->opponentVariantsOrNull($game->ruleset()),
                // promień sonaru gracza (technologia, Epic G) tylko, gdy inny niż bazowy
                'sonarRadius' => $this->sonarRadiusOrNull($game->ruleset()),
            ],
            'fleet' => $fleet,
            'shots' => $shots,
            // opponent shots (AI) snapshot
            'opponentShots' => array_map(
                static fn (array $s) => ['x' => $s['x'], 'y' => $s['y'], 'r' => $s['result']],
                $game->opponentShotsWithResults()
            ),
            // opponent fleet snapshot – optional
            'opponentFleet' => $opponentFleet,
            // Stan AI przeciwnika (kształt zna HuntTargetAI) – opcjonalnie
            'ai' => [] !== $game->aiState() ? $game->aiState() : null,
            // Użycia broni specjalnych per strona (limity trzyma Ruleset)
            'weapons' => $this->weaponUsesOrNull($game),
            // Energia zużyta w bieżącej turze (#59) — tylko, gdy coś zużyto
            'energySpent' => [] !== array_filter($game->energySpent()) ? $game->energySpent() : null,
            // ruch statku wykorzystany w bieżącej turze (#60) — tylko, gdy był
            'moved' => $game->playerMovedThisTurn() ? true : null,
            // układ fortu w bitwie oblężniczej (#119) — tylko, gdy jest
            'fort' => $game->fort()?->toArray(),
            // Iteration 1 meta
            'mode' => $game->mode(),
            'opponent' => $game->opponent(),
            'turn' => $game->turn(),
        ];
    }

    public function toDomain(string $id, array $state): Game
    {
        $ruleset = $this->rulesetFromArray($state['ruleset'] ?? []);
        $statusVal = (string) ($state['status'] ?? GameStatus::Pending->value);
        try {
            $status = GameStatus::from($statusVal);
        } catch (\Throwable) {
            $status = GameStatus::Pending;
        }
        $game = Game::fromSnapshot(new GameId($id), $ruleset, $status);

        // meta (optional in older snapshots)
        if (isset($state['mode']) && is_string($state['mode'])) {
            $game->setMode($state['mode']);
        }
        if (isset($state['opponent']) && is_string($state['opponent'])) {
            $game->setOpponent($state['opponent']);
        }
        if (isset($state['turn']) && is_string($state['turn'])) {
            $turn = $state['turn'];
            $game->setTurn(in_array($turn, ['player', 'opponent', 'none'], true) ? $turn : 'player');
        }

        // fleet from snapshot
        if (isset($state['fleet']) && is_array($state['fleet'])) {
            $ships = [];
            foreach ($state['fleet'] as $s) {
                $ships[] = $this->shipFromArray($s);
            }
            $game->setFleetFromSnapshot($ships);
        }

        // opis fortu (#119) — PRZED flotą przeciwnika: konstrukcje fortu stoją
        // na styk, więc plansza wroga musi to wiedzieć, zanim je odtworzy
        if (!empty($state['fort']) && is_array($state['fort'])) {
            $game->setFortFromSnapshot(Fort::fromArray($state['fort']));
        }

        // opponent fleet from snapshot (optional) — PRZED strzałami gracza:
        // strzały są własnością strony, w którą oddano (targetSide), więc
        // strona przeciwnika musi istnieć zanim je odtworzymy
        if (!empty($state['opponentFleet']) && is_array($state['opponentFleet'])) {
            $ships = [];
            foreach ($state['opponentFleet'] as $s) {
                if (isset($s['x'], $s['y'], $s['o'], $s['l'])) {
                    $ships[] = $this->shipFromArray($s);
                }
            }
            if ([] !== $ships) {
                $game->setOpponentFleetFromSnapshot($ships);
            }
        }

        // shots from snapshot (optional) + hits reconstruction
        if (!empty($state['shots']) && is_array($state['shots'])) {
            // normalize input
            $shots = [];
            foreach ($state['shots'] as $s) {
                if (isset($s['x'], $s['y'])) {
                    $entry = ['x' => (int) $s['x'], 'y' => (int) $s['y']];
                    if (isset($s['r'])) {
                        $entry['r'] = (string) $s['r']; // 'hit' | 'sunk' | 'miss' | 'duplicate'
                    }
                    $shots[] = $entry;
                }
            }

            $game->setShotsFromSnapshot($shots);
        }

        // opponent shots from snapshot (optional)
        if (!empty($state['opponentShots']) && is_array($state['opponentShots'])) {
            $oppShots = [];
            foreach ($state['opponentShots'] as $s) {
                if (isset($s['x'], $s['y'])) {
                    $entry = ['x' => (int) $s['x'], 'y' => (int) $s['y']];
                    if (isset($s['r'])) {
                        $entry['r'] = (string) $s['r'];
                    }
                    $oppShots[] = $entry;
                }
            }

            $game->setOpponentShotsFromSnapshot($oppShots);
        }

        // AI state from snapshot (optional; stary kształt zostanie zignorowany przez HuntTargetAI::fromSnapshot)
        if (!empty($state['ai']) && is_array($state['ai'])) {
            $game->setAiState($state['ai']);
        }

        // weapon uses from snapshot (optional)
        if (!empty($state['weapons']) && is_array($state['weapons'])) {
            $game->setWeaponUsesFromSnapshot($state['weapons']);
        }

        // energia zużyta w turze (#59); brak = pełna pula (snapshoty sprzed energii)
        if (!empty($state['energySpent']) && is_array($state['energySpent'])) {
            $game->setEnergySpentFromSnapshot($state['energySpent']);
        }

        // ruch statku w turze (#60); brak = ruch dostępny
        if (true === ($state['moved'] ?? null)) {
            $game->setPlayerMovedFromSnapshot(true);
        }

        return $game;
    }

    private function rulesetFromArray(array $data): Ruleset
    {
        $board = $data['board'] ?? ['w' => 10, 'h' => 10];
        $size = new BoardSize((int) $board['w'], (int) $board['h']);

        // Skład floty (klucze JSON wracają jako stringi); brak = flota klasyczna,
        // brak składu przeciwnika = lustrzany
        $ships = $this->compositionOrNull($data['ships'] ?? null);
        $opponentShips = $this->compositionOrNull($data['opponentShips'] ?? null);
        // warianty wyposażenia (#95): brak = same domyślne, brak wroga = lustrzane
        $variants = FleetComposition::variantsFromArray($data['variants'] ?? null);
        $opponentVariants = isset($data['opponentVariants']) ? FleetComposition::variantsFromArray($data['opponentVariants']) : null;
        // promień sonaru gracza: brak = bazowy (snapshoty sprzed Epica G)
        $sonarRadius = isset($data['sonarRadius']) ? (int) $data['sonarRadius'] : null;

        return 'fun' === ($data['type'] ?? 'classic')
            ? new FunRuleset($size, $ships, $opponentShips, $variants, $opponentVariants, $sonarRadius)
            : new ClassicRuleset($size, $ships, $opponentShips, $variants, $opponentVariants);
    }

    /**
     * Statek w snapshotcie; `w` (wyposażenie) tylko, gdy inne niż domyślne
     * dla długości — snapshoty sprzed #95 i floty bez wariantów się nie zmieniają.
     *
     * @return array{x:int,y:int,o:string,l:int,w?:array<string,int>}
     */
    private function shipToArray(Ship $s): array
    {
        $out = [
            'x' => $s->start->x,
            'y' => $s->start->y,
            'o' => $s->orientation->value,
            'l' => $s->length,
        ];
        if (!$s->hasDefaultLoadout()) {
            $out['w'] = $s->loadout->toArray();
        }

        return $out;
    }

    /** @param array<string,mixed> $s */
    private function shipFromArray(array $s): Ship
    {
        return new Ship(
            new Coordinate((int) $s['x'], (int) $s['y']),
            Orientation::from((string) $s['o']),
            (int) $s['l'],
            isset($s['w']) && is_array($s['w']) ? Loadout::fromArray($s['w']) : null,
        );
    }

    private function sonarRadiusOrNull(Ruleset $ruleset): ?int
    {
        return $ruleset instanceof FunRuleset && SonarSpec::RADIUS !== $ruleset->sonarRadius() ? $ruleset->sonarRadius() : null;
    }

    /**
     * @param array<int,list<Loadout>> $variants
     *
     * @return array<int,list<array<string,int>>>|null
     */
    private function variantsOrNull(array $variants): ?array
    {
        return [] !== $variants ? FleetComposition::variantsToArray($variants) : null;
    }

    /** @return array<int,list<array<string,int>>>|null */
    private function opponentVariantsOrNull(Ruleset $ruleset): ?array
    {
        $mirror = FleetComposition::variantsWithin($ruleset->variants(), $ruleset->opponentShips());
        $own = FleetComposition::variantsToArray($ruleset->opponentVariants());

        return $own !== FleetComposition::variantsToArray($mirror) ? $own : null;
    }

    /** @return array<int,int>|null */
    private function compositionOrNull(mixed $raw): ?array
    {
        if (empty($raw) || !is_array($raw)) {
            return null;
        }
        $ships = [];
        foreach ($raw as $length => $count) {
            $ships[(int) $length] = (int) $count;
        }

        return $ships;
    }

    /** @return array{player: array<string,int>, opponent: array<string,int>}|null */
    private function weaponUsesOrNull(Game $game): ?array
    {
        $uses = $game->weaponUses();

        return ([] !== $uses['player'] || [] !== $uses['opponent']) ? $uses : null;
    }
}
