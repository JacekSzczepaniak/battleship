<?php

namespace App\Domain\Game;

/**
 * Jedna strona gry: flota na planszy + strzały ODDANE W TĘ stronę (z trafieniami).
 * Game składa się z dwóch takich stron (gracza i przeciwnika) — cała logika
 * trafień/zatopień/duplikatów jest tu, wspólna dla obu stron.
 */
final class BoardSide
{
    private ?Board $board = null;
    /** @var array<string,bool> strzały oddane w tę stronę (klucze "x:y") */
    private array $shotsTaken = [];
    /** @var array<string,bool> celne strzały (podzbiór shotsTaken) */
    private array $hits = [];

    public function __construct(private readonly BoardSize $size)
    {
    }

    public function hasFleet(): bool
    {
        return null !== $this->board;
    }

    /** @return Ship[]|null */
    public function fleet(): ?array
    {
        return $this->board?->ships();
    }

    /**
     * Rozstawia flotę (walidacja pozycji: granice, kolizje, styk — przez Board).
     * Walidacja kompozycji względem rulesetu należy do Game.
     *
     * @param Ship[] $ships
     */
    public function placeFleet(array $ships): void
    {
        if ($this->hasFleet()) {
            throw new \DomainException('Fleet already placed');
        }

        $board = new Board($this->size);
        $board->placeMany($ships);
        $this->board = $board;
    }

    /**
     * Odtworzenie ze snapshotu: buduje planszę, nie rusza zapisanych strzałów.
     *
     * @param Ship[] $ships
     */
    public function setFleetFromSnapshot(array $ships): void
    {
        $board = new Board($this->size);
        $board->placeMany($ships);
        $this->board = $board;
    }

    /**
     * Przyjmuje strzał w tę stronę i zwraca wynik.
     * Po zatopieniu ostatniego statku allShipsHit() zaczyna zwracać true.
     */
    public function receiveShot(Coordinate $c): ShotResult
    {
        if (!$this->hasFleet()) {
            throw new \DomainException('Fleet not placed');
        }

        $key = $this->keyOf($c);
        if (isset($this->shotsTaken[$key])) {
            return ShotResult::Duplicate;
        }
        $this->shotsTaken[$key] = true;

        $hitShip = $this->shipAtKey($key);
        if (null === $hitShip) {
            return ShotResult::Miss;
        }

        $this->hits[$key] = true;

        return $this->isSunk($hitShip) ? ShotResult::Sunk : ShotResult::Hit;
    }

    /** Czy wszystkie komórki wszystkich statków tej strony zostały trafione. */
    public function allShipsHit(): bool
    {
        if (!$this->hasFleet()) {
            return false;
        }

        foreach ($this->board->ships() as $ship) {
            foreach ($this->cellKeys($ship) as $cellKey) {
                if (!isset($this->hits[$cellKey])) {
                    return false;
                }
            }
        }

        return true;
    }

    public function hasShipAt(int $x, int $y): bool
    {
        return null !== $this->shipAtKey($x.':'.$y);
    }

    /** Czy na polu stoi statek, który NIE został jeszcze zatopiony. */
    public function hasUnsunkShipAt(int $x, int $y): bool
    {
        $ship = $this->shipAtKey($x.':'.$y);

        return null !== $ship && !$this->isSunk($ship);
    }

    /** @return list<array{x:int,y:int}> */
    public function shotsTaken(): array
    {
        $out = [];
        foreach (array_keys($this->shotsTaken) as $k) {
            [$x, $y] = array_map('intval', explode(':', $k));
            $out[] = ['x' => $x, 'y' => $y];
        }

        return $out;
    }

    /**
     * Strzały w tę stronę z wyliczonym wynikiem (miss/hit/sunk).
     *
     * @return list<array{x:int,y:int,result:string}>
     */
    public function shotsWithResults(): array
    {
        $out = [];
        foreach (array_keys($this->shotsTaken) as $key) {
            [$x, $y] = array_map('intval', explode(':', $key));

            $result = 'miss';
            if (isset($this->hits[$key])) {
                $ship = $this->shipAtKey($key);
                $result = (null !== $ship && $this->isSunk($ship)) ? 'sunk' : 'hit';
            }

            $out[] = ['x' => $x, 'y' => $y, 'result' => $result];
        }

        return $out;
    }

    /**
     * Odtworzenie strzałów ze snapshotu; trafienia z flag 'r' (nie wymaga planszy).
     *
     * @param array<int, array{x:int,y:int,r?:string}> $shots
     */
    public function setShotsFromSnapshot(array $shots): void
    {
        $this->shotsTaken = [];
        $this->hits = [];

        foreach ($shots as $s) {
            $key = $s['x'].':'.$s['y'];
            $this->shotsTaken[$key] = true;

            $r = $s['r'] ?? null; // 'hit' | 'sunk' | 'miss' | 'duplicate' | null
            if ('hit' === $r || 'sunk' === $r) {
                $this->hits[$key] = true;
            }
        }
    }

    /** Widok "które pola już ostrzelane" — bez odsłaniania statków (dla AI). */
    public function shotsView(): BoardReadModel
    {
        return new ShotsTakenView($this->size->width, $this->size->height, $this->shotsTaken);
    }

    private function keyOf(Coordinate $c): string
    {
        return $c->x.':'.$c->y;
    }

    /** @return list<string> klucze "x:y" komórek statku */
    private function cellKeys(Ship $ship): array
    {
        return array_map(fn (Coordinate $c) => $this->keyOf($c), $ship->cells());
    }

    private function shipAtKey(string $key): ?Ship
    {
        if (!$this->hasFleet()) {
            return null;
        }

        foreach ($this->board->ships() as $ship) {
            if (in_array($key, $this->cellKeys($ship), true)) {
                return $ship;
            }
        }

        return null;
    }

    private function isSunk(Ship $ship): bool
    {
        foreach ($this->cellKeys($ship) as $cellKey) {
            if (!isset($this->hits[$cellKey])) {
                return false;
            }
        }

        return true;
    }
}
