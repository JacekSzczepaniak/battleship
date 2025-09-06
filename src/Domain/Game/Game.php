<?php

namespace App\Domain\Game;

use App\Domain\Shared\GameId;

final class Game
{
    private GameStatus $status = GameStatus::Pending;
    private ?Board $board = null;
    /** @var array<string,bool> */
    private array $shots = [];
    /** @var array<string,bool> */
    private array $hits = [];

    /** @var Ship[]|null */
    private ?array $fleet = null;

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
     * Fires a shot and returns ShotResult.
     *
     * Throws DomainException only when the fleet is not placed.
     */
    public function fireShot(Coordinate $c): ShotResult
    {
        if (null === $this->board) {
            throw new \DomainException('Fleet not placed');
        }
        $key = $c->x.':'.$c->y;
        if (isset($this->shots[$key])) {
            return ShotResult::Duplicate;
        }
        $this->shots[$key] = true;

        $hitShip = null;
        foreach ($this->board->ships() as $ship) {
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
        foreach ($this->board->ships() as $s) {
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
                if (null !== $this->board) {
                    foreach ($this->board->ships() as $ship) {
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
        if (null === $this->board) {
            return false;
        }

        foreach ($this->board->ships() as $ship) {
            foreach ($this->cellsFor($ship) as $cellKey) {
                if (!isset($this->hits[$cellKey])) {
                    return false;
                }
            }
        }

        return true;
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
