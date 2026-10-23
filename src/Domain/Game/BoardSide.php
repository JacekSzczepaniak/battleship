<?php

namespace App\Domain\Game;

use App\Domain\Game\Weapon\WeaponKind;

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

    /** Konstrukcje fortu (#119) stoją na styk; flota zawsze z odstępem. */
    private bool $contactAllowed = false;

    public function __construct(private readonly BoardSize $size)
    {
    }

    /** Strona fortu: konstrukcje mogą się stykać (eksplozja magazynu ma co trafić). */
    public function allowContact(): void
    {
        $this->contactAllowed = true;
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

        $board = new Board($this->size, $this->contactAllowed);
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
        $board = new Board($this->size, $this->contactAllowed);
        $board->placeMany($ships);
        $this->board = $board;
    }

    /**
     * Ruch statku o 1 pole (#60): statek wskazany dowolnym swoim polem płynie
     * wzdłuż swojej osi (tratwa w dowolną z 4 stron). Rusza się tylko statek
     * bez trafienia — trafienia nie zmieniają więc pozycji. Nie wolno wpłynąć
     * na pole, w które strzelano w tę stronę, ani wyjść poza planszę czy stanąć
     * na styk z innym statkiem. Statek zachowuje miejsce we flocie i wyposażenie.
     */
    public function moveShip(Coordinate $cell, Direction $direction): Ship
    {
        $ships = $this->board?->ships() ?? [];
        $index = null;
        foreach ($ships as $i => $ship) {
            if (in_array($this->keyOf($cell), $this->cellKeys($ship), true)) {
                $index = $i;
                break;
            }
        }
        if (null === $index) {
            throw new \DomainException('No own ship at this cell');
        }

        $ship = $ships[$index];
        foreach ($this->cellKeys($ship) as $cellKey) {
            if (isset($this->hits[$cellKey])) {
                throw new \DomainException('Damaged ship cannot move');
            }
        }

        $alongAxis = match ($direction) {
            Direction::E, Direction::W => 1 === $ship->length || Orientation::H === $ship->orientation,
            Direction::N, Direction::S => 1 === $ship->length || Orientation::V === $ship->orientation,
            default => false,
        };
        if (!$alongAxis) {
            throw new \DomainException('Ship can only move along its axis');
        }

        [$dx, $dy] = $direction->vector();
        $x = $ship->start->x + $dx;
        $y = $ship->start->y + $dy;
        $endX = $x + (Orientation::H === $ship->orientation ? $ship->length - 1 : 0);
        $endY = $y + (Orientation::V === $ship->orientation ? $ship->length - 1 : 0);
        if ($x < 0 || $y < 0 || $endX >= $this->size->width || $endY >= $this->size->height) {
            throw new \DomainException('Ship move out of board bounds');
        }

        $moved = new Ship(new Coordinate($x, $y), $ship->orientation, $ship->length, $ship->loadout);
        foreach ($this->cellKeys($moved) as $cellKey) {
            if (isset($this->shotsTaken[$cellKey])) {
                throw new \DomainException('Ship cannot move onto a cell already shot');
            }
        }

        $ships[$index] = $moved;
        $board = new Board($this->size, $this->contactAllowed);
        try {
            $board->placeMany($ships);
        } catch (\DomainException) {
            throw new \DomainException('Ship move would touch another ship');
        }
        $this->board = $board;

        return $moved;
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

    /** Czy na polu stoi niezatopiony nośnik danej broni (wyrzutnia torpedy). */
    public function hasUnsunkCarrierAt(int $x, int $y, WeaponKind $weapon): bool
    {
        $ship = $this->shipAtKey($x.':'.$y);

        return null !== $ship && $ship->loadout->carries($weapon) && !$this->isSunk($ship);
    }

    /** Czy strona ma jeszcze niezatopiony nośnik danej broni. */
    public function hasUnsunkCarrierOf(WeaponKind $weapon): bool
    {
        if (!$this->hasFleet()) {
            return false;
        }
        foreach ($this->board->ships() as $ship) {
            if ($ship->loadout->carries($weapon) && !$this->isSunk($ship)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Komórki niezatopionych statków tej strony; $carrying zawęża do nośników
     * danej broni (legalne wyrzutnie torped).
     *
     * @return list<array{x:int,y:int}>
     */
    public function unsunkShipCells(?WeaponKind $carrying = null): array
    {
        if (!$this->hasFleet()) {
            return [];
        }

        $out = [];
        foreach ($this->board->ships() as $ship) {
            if ($this->isSunk($ship) || (null !== $carrying && !$ship->loadout->carries($carrying))) {
                continue;
            }
            foreach ($ship->cells() as $c) {
                $out[] = ['x' => $c->x, 'y' => $c->y];
            }
        }

        return $out;
    }

    /**
     * Niezatopione statki tej strony z liczbą trafionych pól (abordaż, #118);
     * klucz = indeks statku we flocie.
     *
     * @return array<int, array{ship: Ship, hits: int}>
     */
    public function unsunkShips(): array
    {
        if (!$this->hasFleet()) {
            return [];
        }

        $out = [];
        foreach ($this->board->ships() as $index => $ship) {
            if ($this->isSunk($ship)) {
                continue;
            }
            $hits = count(array_filter($this->cellKeys($ship), fn (string $key) => isset($this->hits[$key])));
            $out[$index] = ['ship' => $ship, 'hits' => $hits];
        }

        return $out;
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
