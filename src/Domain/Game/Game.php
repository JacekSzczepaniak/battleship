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
     * Zwraca informację czy gra jest zakończona.
     * Oparty o status oraz (asekuracyjnie) o faktyczne trafienia wszystkich pól.
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
        $self->status = $status; // kontrolujemy wartości w repo/mapperze

        return $self;
    }

    /** @return Ship[]|null */
    public function fleet(): ?array
    {
        return $this->fleet;
    }

    /**
     * Używane przy odczycie ze snapshotu (bez walidacji biznesowej).
     *
     * @param Ship[] $ships
     */
    public function setFleetFromSnapshot(array $ships): void
    {
        $this->fleet = $ships;
        // odbuduj planszę, aby fireShot działał po odczycie z repozytorium
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

        // walidacja zestawu statków wg ruleset
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

        // walidacja pozycji na planszy
        $board = new Board($this->ruleset->boardSize());
        $board->placeMany($ships);

        $this->fleet = $ships;
        $this->board = $board; // kluczowe: przypisz planszę do właściwości obiektu
        $this->status = GameStatus::InProgress; // opcjonalnie: uznajmy, że po rozstawieniu gra startuje
    }

    /**
     * Oddaj strzał. Zwraca ShotResult.
     *
     * Rzuca DomainException tylko gdy flota nie jest rozstawiona.
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

        // sprawdź czy zatopiony ten statek
        $sunk = true;
        foreach ($this->cellsFor($hitShip) as $cellKey) {
            if (!isset($this->hits[$cellKey])) {
                $sunk = false;
                break;
            }
        }

        // sprawdź czy wygrana (wszystkie pola wszystkich statków trafione)
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
            // jeżeli masz taki stan w GameStatus
            $this->status = GameStatus::Won;
        }

        return $sunk ? ShotResult::Sunk : ShotResult::Hit;
    }

    /** @return list<string> klucze 'x:y' pól zajmowanych przez statek */
    private function cellsFor(Ship $ship): array
    {
        $cells = [];
        for ($i = 0; $i < $ship->length; ++$i) {
            // używaj $start (spójnie z konstrukcją Ship i fabrykami)
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
        // u nas: shots = strzały (bool), hits = trafienia (bool)
        $this->shots = [];
        $this->hits = [];

        foreach ($shots as $s) {
            $key = $s['x'].':'.$s['y'];
            $this->shots[$key] = true;

            // jeżeli snapshot zawiera wynik strzału, odtwórz trafienia
            $r = $s['r'] ?? null; // 'hit' | 'sunk' | 'miss' | 'duplicate' | null
            if ('hit' === $r || 'sunk' === $r) {
                $this->hits[$key] = true;
            }
        }
    }

    /**
     * Zwraca listę strzałów z obliczonym wynikiem dla każdego strzału.
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function shotsWithResults(): array
    {
        $out = [];
        foreach (array_keys($this->shots) as $key) {
            [$x, $y] = array_map('intval', explode(':', $key));

            // domyślnie pudło
            $result = 'miss';

            // jeżeli był trafiony ten klucz -> hit lub sunk
            if (isset($this->hits[$key])) {
                $result = 'hit';

                // jeśli mamy planszę i flotę, możemy sprawdzić czy zatopiony
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
}
