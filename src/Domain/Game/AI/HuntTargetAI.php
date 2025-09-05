<?php

namespace App\Domain\Game\AI;

use App\Domain\Game\Shooter;
use App\Domain\Game\BoardReadModel;
use App\Domain\Game\Coordinate;
use App\Domain\Game\ShotResult;

final class HuntTargetAI implements Shooter
{
    /** @var array<string,bool> */
    private array $tried = [];
    /** @var Coordinate[] */
    private array $targets = [];
    private ?Coordinate $firstHit = null;

    /**
     * @param int|null $checkerboardMinSize Minimalny rozmiar planszy od którego włączamy filtr „szachownicy”.
     *                                      Gdy null – filtr wyłączony.
     * @param int $checkerboardOffset       0 lub 1 – parzystość dla filtra: (x+y)%2==offset.
     */
    public function __construct(
        private ?int $checkerboardMinSize = 10,
        private int $checkerboardOffset = 0,
    ) {
    }

    public function nextShot(BoardReadModel $board): Coordinate
    {
        // Target – najpierw sąsiedzi
        while ($this->targets) {
            $c = array_pop($this->targets);
            if ($this->isLegal($c, $board) && !$this->already($c)) {
                return $this->mark($c);
            }
        }

        // Hunt – losowanie z opcjonalnym filtrem „szachownicy”
        $N = $board->size();

        // Jeśli filtr miałby być aktywny, ale wyczerpaliśmy już wszystkie pola tej parzystości – odpuść filtr
        $useCheckerboard = $this->shouldUseCheckerboard($N) && $this->hasFreeCellsOnParity($N, $this->checkerboardOffset);

        // Dodatkowy bezpiecznik: ogranicz liczbę prób losowania
        $maxAttempts = max(256, $N * $N * 2);
        $attempts = 0;

        // Szukaj następnego legalnego pola – nie używamy do/while na niezainicjalizowanym $c
        while (true) {
            $attempts++;

            if ($attempts > $maxAttempts) {
                // awaryjnie przełącz tryb: najpierw druga parzystość, potem kompletnie wyłącz filtr
                if ($useCheckerboard && $this->hasFreeCellsOnParity($N, 1 - ($this->checkerboardOffset % 2))) {
                    $this->checkerboardOffset = 1 - ($this->checkerboardOffset % 2);
                    $attempts = 0; // zresetuj próby po zmianie parzystości
                } else {
                    $useCheckerboard = false;
                    $attempts = 0; // reset po wyłączeniu filtra
                }
            }

            $x = random_int(0, $N - 1);
            $y = random_int(0, $N - 1);

            if ($useCheckerboard && ((($x + $y) % 2) !== ($this->checkerboardOffset % 2))) {
                continue;
            }

            $c = new Coordinate($x, $y);

            if ($this->already($c)) {
                continue;
            }
            if ($board->wasTried($c)) {
                continue;
            }

            return $this->mark($c);
        }
    }

    public function notify(Coordinate $c, ShotResult $result): void
    {
        if ($result === ShotResult::Miss) {
            return;
        }

        if ($result === ShotResult::Hit) {
            if ($this->firstHit === null) {
                $this->firstHit = $c;
                $this->pushNeighbors($c);
            } else {
                // Drugi/trzeci hit – spróbuj iść po linii
                $this->pushLineFrom($this->firstHit, $c);
            }
            return;
        }

        // SUNK – reset polowania
        $this->firstHit = null;
        $this->targets = [];
    }

    private function shouldUseCheckerboard(int $boardSize): bool
    {
        return $this->checkerboardMinSize !== null && $boardSize >= $this->checkerboardMinSize;
    }

    /** Czy pozostały wolne (niepróbowane) pola o danej parzystości wg lokalnego stanu AI. */
    private function hasFreeCellsOnParity(int $N, int $offset): bool
    {
        // liczba pól o danej parzystości
        $total = intdiv($N * $N + (($offset % 2) === 0 ? 1 : 0), 2);
        // policz ile już próbowaliśmy o tej parzystości
        $triedOnParity = 0;
        foreach ($this->tried as $key => $v) {
            if (!$v) continue;
            [$x, $y] = array_map('intval', explode(':', (string)$key, 2));
            if ((($x + $y) % 2) === ($offset % 2)) {
                $triedOnParity++;
            }
        }
        return $triedOnParity < $total;
    }

    private function pushNeighbors(Coordinate $c): void
    {
        $this->targets[] = new Coordinate($c->x + 1, $c->y);
        $this->targets[] = new Coordinate($c->x - 1, $c->y);
        $this->targets[] = new Coordinate($c->x, $c->y + 1);
        $this->targets[] = new Coordinate($c->x, $c->y - 1);
    }

    private function pushLineFrom(Coordinate $a, Coordinate $b): void
    {
        if ($a->x === $b->x) { // pion
            $x = $a->x;
            $this->targets[] = new Coordinate($x, min($a->y, $b->y) - 1);
            $this->targets[] = new Coordinate($x, max($a->y, $b->y) + 1);
        } elseif ($a->y === $b->y) { // poziom
            $y = $a->y;
            $this->targets[] = new Coordinate(min($a->x, $b->x) - 1, $y);
            $this->targets[] = new Coordinate(max($a->x, $b->x) + 1, $y);
        } else {
            $this->pushNeighbors($b);
        }
    }

    private function key(Coordinate $c): string
    {
        return $c->x . ':' . $c->y;
    }

    private function already(Coordinate $c): bool
    {
        return $this->tried[$this->key($c)] ?? false;
    }

    private function mark(Coordinate $c): Coordinate
    {
        $this->tried[$this->key($c)] = true;
        return $c;
    }

    private function isLegal(Coordinate $c, BoardReadModel $b): bool
    {
        return $c->x >= 0 && $c->y >= 0 && $c->x < $b->size() && $c->y < $b->size();
    }
}
