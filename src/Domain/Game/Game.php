<?php

namespace App\Domain\Game;

use App\Domain\Shared\GameId;

final class Game
{
    private GameStatus $status = GameStatus::Pending;
    private ?Board $board = null; // plansza gracza
    /** @var array<string,bool> */
    private array $shots = [];
    /** @var array<string,bool> */
    private array $hits = [];

    /** @var Ship[]|null */
    private ?array $fleet = null; // flota gracza

    // Flota przeciwnika i jego plansza (na potrzeby strzałów gracza)
    private ?Board $opponentBoard = null;
    /** @var Ship[]|null */
    private ?array $opponentFleet = null;

    // Iteration 1: minimalne meta gry (na razie jako proste stringi)
    private string $mode = 'standard';      // 'standard' | 'nonstandard'
    private string $opponent = 'mock';      // 'mock' | 'ai' | 'pvp'
    private string $turn = 'player';        // 'player' | 'opponent'

    /**
     * Strzały przeciwnika na planszę gracza (keys "x:y"), lustrzana struktura do $shots/$hits.
     * Używane przez mock AI i do projekcji overlayu na planszy gracza.
     * @var array<string,bool>
     */
    private array $opponentShots = [];
    /** @var array<string,bool> */
    private array $opponentHits = [];

    // AI (heurystyka v1)
    private string $aiMode = 'hunt'; // 'hunt' | 'target'
    /** @var array<int, array{x:int,y:int}> */
    private array $aiHitsStreak = [];
    private ?string $aiDirection = null; // 'h' | 'v' | null
    /** @var array<int, array{x:int,y:int}> */
    private array $aiQueue = [];

    public function __construct(
        private GameId $id,
        private Ruleset $ruleset,
    ) {
    }

    public static function create(Ruleset $ruleset): self
    {
        return new self(GameId::new(), $ruleset);
    }

    public function id(): GameId
    {
        return $this->id;
    }

    public function ruleset(): Ruleset
    {
        return $this->ruleset;
    }

    public function status(): GameStatus
    {
        return $this->status;
    }

    /**
     * Returns whether the game is finished.
     * Checks status and (as a safeguard) actual hits on all ship cells.
     */
    public function isFinished(): bool
    {
        if (GameStatus::Won === $this->status) {
            return true;
        }

        return $this->allShipsSunk();
    }

    public static function fromSnapshot(GameId $id, Ruleset $ruleset, GameStatus $status): self
    {
        $self = new self($id, $ruleset);
        $self->status = $status;

        return $self;
    }

    public function mode(): string
    {
        return $this->mode;
    }

    public function setMode(string $mode): void
    {
        $this->mode = $mode;
    }

    public function opponent(): string
    {
        return $this->opponent;
    }

    public function setOpponent(string $opponent): void
    {
        $this->opponent = $opponent;
    }

    public function turn(): string
    {
        return $this->turn;
    }

    public function setTurn(string $turn): void
    {
        $this->turn = $turn;
    }

    /** @return Ship[]|null */
    public function fleet(): ?array
    {
        return $this->fleet;
    }

    /**
     * Used when loading from a snapshot (no business validation).
     *
     * @param Ship[] $ships
     */
    public function setFleetFromSnapshot(array $ships): void
    {
        $this->fleet = $ships;
        // rebuild the board so fireShot works after loading from repository
        $board = new Board($this->ruleset->boardSize());
        $board->placeMany($ships);
        $this->board = $board;
    }

    /**
     * @param Ship[] $ships
     */
    public function placeFleet(array $ships): void
    {
        if (null !== $this->fleet) {
            throw new \DomainException('Fleet already placed');
        }

        // validate fleet composition against ruleset
        $expected = $this->ruleset->allowedShips();
        $got = [];
        foreach ($ships as $s) {
            $got[$s->length] = ($got[$s->length] ?? 0) + 1;
        }
        ksort($expected);
        ksort($got);
        if ($got !== $expected) {
            throw new \DomainException('Invalid fleet composition');
        }

        // validate positions on the board
        $board = new Board($this->ruleset->boardSize());
        $board->placeMany($ships);

        $this->fleet = $ships;
        $this->board = $board;
        $this->status = GameStatus::InProgress;
    }

    /**
     * Ustawia flotę przeciwnika (walidowana jak flota gracza) i buduje planszę przeciwnika.
     * @param Ship[] $ships
     */
    public function placeOpponentFleet(array $ships): void
    {
        if (null !== $this->opponentFleet) {
            throw new \DomainException('Opponent fleet already placed');
        }

        $board = new Board($this->ruleset->boardSize());
        $board->placeMany($ships);

        $this->opponentFleet = $ships;
        $this->opponentBoard = $board;
    }

    /**
     * Używane przy odtwarzaniu ze snapshotu (bez walidacji biznesowej poza budową planszy).
     * @param Ship[] $ships
     */
    public function setOpponentFleetFromSnapshot(array $ships): void
    {
        $this->opponentFleet = $ships;
        $board = new Board($this->ruleset->boardSize());
        $board->placeMany($ships);
        $this->opponentBoard = $board;
    }

    /** @return Ship[]|null */
    public function opponentFleet(): ?array
    {
        return $this->opponentFleet;
    }

    /**
     * Fires a shot and returns ShotResult.
     *
     * Throws DomainException only when the fleet is not placed.
     */
    public function fireShot(Coordinate $c): ShotResult
    {
        // Wsteczna kompatybilność: jeśli nie ustawiono opponentBoard, strzelaj w board gracza
        $targetBoard = $this->opponentBoard ?? $this->board;
        if (null === $targetBoard) {
            throw new \DomainException('Opponent fleet not placed');
        }
        $key = $c->x.':'.$c->y;
        if (isset($this->shots[$key])) {
            return ShotResult::Duplicate;
        }
        $this->shots[$key] = true;

        $hitShip = null;
        foreach ($targetBoard->ships() as $ship) {
            foreach ($this->cellsFor($ship) as $cellKey) {
                if ($cellKey === $key) {
                    $hitShip = $ship;
                    $this->hits[$key] = true;
                    break 2;
                }
            }
        }

        if (null === $hitShip) {
            return ShotResult::Miss;
        }

        // check if the hit ship is sunk
        $sunk = true;
        foreach ($this->cellsFor($hitShip) as $cellKey) {
            if (!isset($this->hits[$cellKey])) {
                $sunk = false;
                break;
            }
        }

        // check if all ships are sunk (win)
        $allHit = true;
        foreach ($targetBoard->ships() as $s) {
            foreach ($this->cellsFor($s) as $cellKey) {
                if (!isset($this->hits[$cellKey])) {
                    $allHit = false;
                    break 2;
                }
            }
        }

        if ($allHit) {
            $this->status = GameStatus::Won;
        }

        return $sunk ? ShotResult::Sunk : ShotResult::Hit;
    }

    /**
     * Wykonuje strzał przeciwnika na planszy gracza i zwraca wynik jako string: hit|miss|sunk|duplicate.
     * Gdy wszystkie komórki floty gracza trafione przez przeciwnika – ustawia status Lost.
     */
    public function fireOpponentShot(Coordinate $c): string
    {
        if (null === $this->board) {
            throw new \DomainException('Fleet not placed');
        }

        $key = $c->x.':'.$c->y;
        if (isset($this->opponentShots[$key])) {
            return 'duplicate';
        }
        $this->opponentShots[$key] = true;

        $hitShip = null;
        foreach ($this->board->ships() as $ship) {
            foreach ($this->cellsFor($ship) as $cellKey) {
                if ($cellKey === $key) {
                    $hitShip = $ship;
                    $this->opponentHits[$key] = true;
                    break 2;
                }
            }
        }

        if (null === $hitShip) {
            return 'miss';
        }

        // czy zatopiony ten statek (przez przeciwnika)
        $sunk = true;
        foreach ($this->cellsFor($hitShip) as $cellKey) {
            if (!isset($this->opponentHits[$cellKey])) {
                $sunk = false;
                break;
            }
        }

        // czy wszystkie statki gracza trafione przez przeciwnika → przegrana
        $allOpponentHit = true;
        foreach ($this->board->ships() as $s) {
            foreach ($this->cellsFor($s) as $cellKey) {
                if (!isset($this->opponentHits[$cellKey])) {
                    $allOpponentHit = false;
                    break 2;
                }
            }
        }
        if ($allOpponentHit) {
            $this->status = GameStatus::Lost;
        }

        $result = $sunk ? 'sunk' : 'hit';

        // Aktualizacja stanu AI
        $this->onAiAfterShot($c, $result);

        return $result;
    }

    /** Wybiera następny cel przeciwnika wg heurystyki (hunt/target). */
    public function chooseOpponentTarget(): Coordinate
    {
        $size = $this->ruleset->boardSize();

        // TARGET: korzystaj z kolejki kandydatów (czyszcząc z nieprawidłowych pozycji)
        if ($this->aiMode === 'target') {
            while (!empty($this->aiQueue)) {
                $cand = array_shift($this->aiQueue);
                if ($cand === null) break;
                if ($this->isWithin($cand['x'], $cand['y'], $size->width, $size->height) && !$this->isOpponentShotTaken($cand['x'], $cand['y'])) {
                    return new Coordinate($cand['x'], $cand['y']);
                }
            }
            // jeśli kolejka pusta → wróć do hunt
            $this->aiMode = 'hunt';
            $this->aiDirection = null;
            $this->aiHitsStreak = [];
        }

        // HUNT: checkerboard (najpierw jedno „kolorowisko”), potem drugie
        $phases = [0, 1];
        foreach ($phases as $phase) {
            for ($yy = 0; $yy < $size->height; ++$yy) {
                for ($xx = 0; $xx < $size->width; ++$xx) {
                    if ((($xx + $yy) & 1) !== $phase) {
                        continue; // najpierw pola o (x+y)%2 === phase
                    }
                    if (!$this->isOpponentShotTaken($xx, $yy)) {
                        return new Coordinate($xx, $yy);
                    }
                }
            }
        }

        // awaryjnie (wszystko ostrzelane) – zwróć 0,0
        return new Coordinate(0, 0);
    }

    /** Ustawia stan AI ze snapshotu. */
    public function setAiStateFromSnapshot(array $state): void
    {
        $mode = $state['mode'] ?? 'hunt';
        $direction = $state['direction'] ?? null;
        $hits = $state['hitsStreak'] ?? [];
        $queue = $state['queue'] ?? [];

        $this->aiMode = in_array($mode, ['hunt','target'], true) ? $mode : 'hunt';
        $this->aiDirection = in_array($direction, ['h','v'], true) ? $direction : null;
        $this->aiHitsStreak = [];
        foreach ($hits as $h) {
            if (isset($h['x'], $h['y'])) $this->aiHitsStreak[] = ['x' => (int)$h['x'], 'y' => (int)$h['y']];
        }
        $this->aiQueue = [];
        foreach ($queue as $q) {
            if (isset($q['x'], $q['y'])) $this->aiQueue[] = ['x' => (int)$q['x'], 'y' => (int)$q['y']];
        }
    }

    /** Zwraca stan AI do snapshotu. */
    public function aiStateToArray(): array
    {
        return [
            'mode' => $this->aiMode,
            'direction' => $this->aiDirection,
            'hitsStreak' => $this->aiHitsStreak,
            'queue' => $this->aiQueue,
        ];
    }

    private function isOpponentShotTaken(int $x, int $y): bool
    {
        return isset($this->opponentShots[$x.':'.$y]);
    }

    private function isWithin(int $x, int $y, int $w, int $h): bool
    {
        return $x >= 0 && $y >= 0 && $x < $w && $y < $h;
    }

    /** Aktualizuje stan AI po wyniku strzału przeciwnika. */
    private function onAiAfterShot(Coordinate $c, string $result): void
    {
        $size = $this->ruleset->boardSize();

        if ($result === 'miss' || $result === 'duplicate') {
            // przy pudle: zostaw tryb; kolejka oczyści się w chooseOpponentTarget()
            return;
        }

        if ($result === 'sunk') {
            // reset po zatopieniu
            $this->aiMode = 'hunt';
            $this->aiDirection = null;
            $this->aiHitsStreak = [];
            $this->aiQueue = [];
            return;
        }

        // HIT: przechodzimy do target i dokładamy kandydatów
        $this->aiMode = 'target';
        $this->aiHitsStreak[] = ['x' => $c->x, 'y' => $c->y];

        // ustal kierunek, jeśli mamy co najmniej dwa trafienia w linii
        if (count($this->aiHitsStreak) >= 2 && $this->aiDirection === null) {
            $n = count($this->aiHitsStreak);
            $a = $this->aiHitsStreak[$n-2];
            $b = $this->aiHitsStreak[$n-1];
            if ($a['y'] === $b['y']) $this->aiDirection = 'h';
            elseif ($a['x'] === $b['x']) $this->aiDirection = 'v';
        }

        // dodaj kandydatów
        if ($this->aiDirection === null) {
            // brak kierunku: sąsiedzi N,E,S,W bieżącego trafienia
            $neigh = [
                ['x'=>$c->x, 'y'=>$c->y-1], // N
                ['x'=>$c->x+1, 'y'=>$c->y], // E
                ['x'=>$c->x, 'y'=>$c->y+1], // S
                ['x'=>$c->x-1, 'y'=>$c->y], // W
            ];
            foreach ($neigh as $p) {
                if ($this->isWithin($p['x'],$p['y'],$size->width,$size->height) && !$this->isOpponentShotTaken($p['x'],$p['y'])) {
                    $this->enqueueIfNew($p['x'], $p['y']);
                }
            }
        } else {
            // mamy kierunek: spróbuj rozszerzyć w obu kierunkach wzdłuż linii trafień
            $xs = array_column($this->aiHitsStreak, 'x');
            $ys = array_column($this->aiHitsStreak, 'y');
            if ($this->aiDirection === 'h') {
                $y = end($ys);
                $minX = min($xs); $maxX = max($xs);
                $cands = [ ['x'=>$minX-1,'y'=>$y], ['x'=>$maxX+1,'y'=>$y] ];
                foreach ($cands as $p) {
                    if ($this->isWithin($p['x'],$p['y'],$size->width,$size->height) && !$this->isOpponentShotTaken($p['x'],$p['y'])) {
                        $this->enqueueIfNew($p['x'], $p['y']);
                    }
                }
            } else { // 'v'
                $x = end($xs);
                $minY = min($ys); $maxY = max($ys);
                $cands = [ ['x'=>$x,'y'=>$minY-1], ['x'=>$x,'y'=>$maxY+1] ];
                foreach ($cands as $p) {
                    if ($this->isWithin($p['x'],$p['y'],$size->width,$size->height) && !$this->isOpponentShotTaken($p['x'],$p['y'])) {
                        $this->enqueueIfNew($p['x'], $p['y']);
                    }
                }
            }
        }
    }

    private function enqueueIfNew(int $x, int $y): void
    {
        $key = $x.':'.$y;
        foreach ($this->aiQueue as $q) {
            if ($q['x'].':'.$q['y'] === $key) return;
        }
        $this->aiQueue[] = ['x'=>$x,'y'=>$y];
    }

    /** @return list<string> keys 'x:y' of cells occupied by the ship */
    private function cellsFor(Ship $ship): array
    {
        $cells = [];
        for ($i = 0; $i < $ship->length; ++$i) {
            $x = $ship->start->x + (Orientation::H === $ship->orientation ? $i : 0);
            $y = $ship->start->y + (Orientation::V === $ship->orientation ? $i : 0);
            $cells[] = $x.':'.$y;
        }

        return $cells;
    }

    /** @return list<array{x:int,y:int}> */
    public function shots(): array
    {
        $out = [];
        foreach (array_keys($this->shots) as $k) {
            [$x,$y] = array_map('intval', explode(':', $k));
            $out[] = ['x' => $x, 'y' => $y];
        }

        return $out;
    }

    /** @return list<array{x:int,y:int}> */
    public function opponentShots(): array
    {
        $out = [];
        foreach (array_keys($this->opponentShots) as $k) {
            [$x,$y] = array_map('intval', explode(':', $k));
            $out[] = ['x' => $x, 'y' => $y];
        }

        return $out;
    }

    /** @param array<int, array{x:int,y:int,r?:string}> $shots */
    public function setShotsFromSnapshot(array $shots): void
    {
        // in domain: shots = fired positions (bool), hits = successful hits (bool)
        $this->shots = [];
        $this->hits = [];

        foreach ($shots as $s) {
            $key = $s['x'].':'.$s['y'];
            $this->shots[$key] = true;

            // if snapshot contains result, restore hits
            $r = $s['r'] ?? null; // 'hit' | 'sunk' | 'miss' | 'duplicate' | null
            if ('hit' === $r || 'sunk' === $r) {
                $this->hits[$key] = true;
            }
        }
    }

    /** @param array<int, array{x:int,y:int,r?:string}> $shots */
    public function setOpponentShotsFromSnapshot(array $shots): void
    {
        $this->opponentShots = [];
        $this->opponentHits = [];
        foreach ($shots as $s) {
            $key = $s['x'].':'.$s['y'];
            $this->opponentShots[$key] = true;
            $r = $s['r'] ?? null;
            if ('hit' === $r || 'sunk' === $r) {
                $this->opponentHits[$key] = true;
            }
        }
    }

    /**
     * Returns shots with calculated result for each shot.
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function shotsWithResults(): array
    {
        $out = [];
        foreach (array_keys($this->shots) as $key) {
            [$x, $y] = array_map('intval', explode(':', $key));

            $result = 'miss';

            if (isset($this->hits[$key])) {
                $result = 'hit';

                // if we have a board, determine if that hit sunk a ship
                $tb = $this->opponentBoard ?? $this->board;
                if (null !== $tb) {
                    foreach ($tb->ships() as $ship) {
                        $cells = $this->cellsFor($ship);
                        if (in_array($key, $cells, true)) {
                            $sunk = true;
                            foreach ($cells as $cellKey) {
                                if (!isset($this->hits[$cellKey])) {
                                    $sunk = false;
                                    break;
                                }
                            }
                            $result = $sunk ? 'sunk' : 'hit';
                            break;
                        }
                    }
                }
            }

        $out[] = ['x' => $x, 'y' => $y, 'result' => $result];
        }

        return $out;
    }

    private function allShipsSunk(): bool
    {
        $tb = $this->opponentBoard ?? $this->board;
        if (null === $tb) {
            return false;
        }

        foreach ($tb->ships() as $ship) {
            foreach ($this->cellsFor($ship) as $cellKey) {
                if (!isset($this->hits[$cellKey])) {
                    return false;
                }
            }
        }

        return true;
    }

    /** Zwraca listę strzałów przeciwnika z wynikiem. @return list<array{x:int,y:int,result:string}> */
    public function opponentShotsWithResults(): array
    {
        $out = [];
        foreach (array_keys($this->opponentShots) as $key) {
            [$x, $y] = array_map('intval', explode(':', $key));

            $result = isset($this->opponentHits[$key]) ? 'hit' : 'miss';

            if (isset($this->opponentHits[$key]) && null !== $this->board) {
                foreach ($this->board->ships() as $ship) {
                    $cells = $this->cellsFor($ship);
                    if (in_array($key, $cells, true)) {
                        $sunk = true;
                        foreach ($cells as $cellKey) {
                            if (!isset($this->opponentHits[$cellKey])) {
                                $sunk = false;
                                break;
                            }
                        }
                        $result = $sunk ? 'sunk' : 'hit';
                        break;
                    }
                }
            }

            $out[] = ['x' => $x, 'y' => $y, 'result' => $result];
        }

        return $out;
    }

    /**
     * Torpedo: moves across the entire board in a given direction.
     * For each cell along the line it calls fireShot() and returns the list of results.
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function fireTorpedo(Coordinate $start, Direction $direction): array
    {
        if (null === $this->board) {
            throw new \DomainException('Fleet not placed');
        }

        $this->ruleset->fireTorpedo();

        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        $x = $start->x;
        $y = $start->y;

        if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
            throw new \DomainException('Torpedo start outside board');
        }

        // Direction vector
        [$dx, $dy] = match ($direction) {
            Direction::N => [0, -1],
            Direction::S => [0, 1],
            Direction::E => [1, 0],
            Direction::W => [-1, 0],
        };

        $results = [];

        // Include the start point and each subsequent point until hitting the edge (inclusive)
        $cx = $x;
        $cy = $y;
        while ($cx >= 0 && $cy >= 0 && $cx < $w && $cy < $h) {
            $r = $this->fireShot(new Coordinate($cx, $cy));
            $results[] = ['x' => $cx, 'y' => $cy, 'result' => $r->value];
            $cx += $dx;
            $cy += $dy;
        }

        return $results;
    }

    // ... existing code ...
    /**
     * Sonar ping: reveals occupancy info for the center and up to $radius cells
     * in each cardinal direction (cross shape). It does not modify shots/hits.
     *
     * @return list<array{x:int,y:int,occupied:bool}>
     */
    public function sonarPing(Coordinate $center, int $radius = 3): array
    {
        if (null === $this->board) {
            throw new \DomainException('Fleet not placed');
        }

        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        $inBounds = static fn (int $x, int $y) => $x >= 0 && $y >= 0 && $x < $w && $y < $h;

        $cells = [];
        // center
        $cells[] = [$center->x, $center->y];

        // N/E/S/W up to radius
        for ($i = 1; $i <= $radius; ++$i) {
            $cells[] = [$center->x, $center->y - $i]; // N
            $cells[] = [$center->x + $i, $center->y]; // E
            $cells[] = [$center->x, $center->y + $i]; // S
            $cells[] = [$center->x - $i, $center->y]; // W
        }

        $results = [];
        foreach ($cells as [$x, $y]) {
            if (!$inBounds($x, $y)) {
                continue;
            }
            $occupied = $this->isShipAt($x, $y);
            $results[] = ['x' => $x, 'y' => $y, 'occupied' => $occupied];
        }

        return $results;
    }

    public function sendAirRaid(Coordinate $start, Area $area): array
    {
        if (null === $this->board) {
            throw new \DomainException('Fleet not placed');
        }

        //sprawdzam czy start jest na planszy
        $w = $this->ruleset->boardSize()->width;
        $h = $this->ruleset->boardSize()->height;

        $x = $start->x;
        $y = $start->y;

        if ($x < 0 || $y < 0 || $x >= $w || $y >= $h) {
            throw new \DomainException('Air Raid start outside board');
        }
        //(5-9)
        $areaStartX = ($start->x - $area->height) > 0 ? $start->x - $area->height : 1;
        $areaEndX = ($start->x + $area->height) >= $this->ruleset->boardSize()->height ?
            $this->ruleset->boardSize()->height - 1 : $start->x + $area->height;

        $areaStartY = ($start->y - $area->width) > 0 ? $start->y - $area->width : 1;
        $areaEndY = ($start->y + $area->width) >= $this->ruleset->boardSize()->width ?
            $this->ruleset->boardSize()->width - 1 : $start->y + $area->width;

        if ($areaEndX - $areaStartX > $this->ruleset->airRaidSize()->width
        || $areaEndY - $areaStartY > $this->ruleset->airRaidSize()->height) {
            throw new \DomainException('Air Raid area is oversize');
        }

        $results = [];

        for ($x = $areaStartX; $x <= $areaEndX; ++$x) {
            for ($y = $areaStartY; $y <= $areaEndY; ++$y) {
                $r = $this->fireShot(new Coordinate($x, $y));
                $results[] = ['x' => $x, 'y' => $y, 'result' => $r->value];
            }
        }

        return $results;
    }

    /**
     * Checks whether any ship occupies the given coordinate.
     */
    private function isShipAt(int $x, int $y): bool
    {
        if (null === $this->board) {
            return false;
        }
        $key = $x.':'.$y;
        foreach ($this->board->ships() as $ship) {
            foreach ($this->cellsFor($ship) as $cellKey) {
                if ($cellKey === $key) {
                    return true;
                }
            }
        }

        return false;
    }
}
