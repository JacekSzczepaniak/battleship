<?php

namespace App\Domain\Game\AI;

use App\Domain\Game\BoardReadModel;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Shooter;
use App\Domain\Game\ShotResult;

/**
 * Jedyna implementacja AI przeciwnika: tryb hunt (losowanie z filtrem „szachownicy”)
 * + tryb target (dobijanie trafionego statku po sąsiadach/linii).
 *
 * Stan wewnętrzny da się zapisać i odtworzyć przez toSnapshot()/fromSnapshot(),
 * dzięki czemu AI przeżywa cykl request→response (snapshot gry w repozytorium).
 * Mapa pól już ostrzelanych NIE jest częścią snapshotu – źródłem prawdy jest
 * plansza (BoardReadModel::wasTried), więc stare/nieznane snapshoty degradują
 * się łagodnie do świeżego stanu bez ryzyka powtórnych strzałów.
 */
final class HuntTargetAI implements Shooter
{
    /** @var array<string,bool> */
    private array $tried = [];
    /** @var Coordinate[] */
    private array $targets = [];
    private ?Coordinate $firstHit = null;

    /**
     * @param int|null $checkerboardMinSize Minimalny bok planszy, od którego włączamy filtr „szachownicy”.
     *                                      Gdy null – filtr wyłączony.
     * @param int      $checkerboardOffset  0 lub 1 – parzystość dla filtra: (x+y)%2==offset
     */
    public function __construct(
        private ?int $checkerboardMinSize = 10,
        private int $checkerboardOffset = 0,
    ) {
    }

    /**
     * Odtwarza stan AI ze snapshotu gry. Nieznany/stary kształt danych → świeży stan.
     *
     * @param array<string,mixed> $state
     */
    public static function fromSnapshot(array $state, ?int $checkerboardMinSize = 10): self
    {
        $offset = (int) ($state['checkerboardOffset'] ?? 0);
        $ai = new self($checkerboardMinSize, 1 === $offset ? 1 : 0);

        $firstHit = $state['firstHit'] ?? null;
        if (is_array($firstHit) && isset($firstHit['x'], $firstHit['y'])) {
            $ai->firstHit = new Coordinate((int) $firstHit['x'], (int) $firstHit['y']);
        }

        foreach ((array) ($state['targets'] ?? []) as $t) {
            if (is_array($t) && isset($t['x'], $t['y'])) {
                $ai->targets[] = new Coordinate((int) $t['x'], (int) $t['y']);
            }
        }

        return $ai;
    }

    /** @return array{checkerboardOffset:int, firstHit:array{x:int,y:int}|null, targets:list<array{x:int,y:int}>} */
    public function toSnapshot(): array
    {
        return [
            'checkerboardOffset' => $this->checkerboardOffset,
            'firstHit' => null !== $this->firstHit
                ? ['x' => $this->firstHit->x, 'y' => $this->firstHit->y]
                : null,
            'targets' => array_values(array_map(
                static fn (Coordinate $c) => ['x' => $c->x, 'y' => $c->y],
                $this->targets
            )),
        ];
    }

    public function nextShot(BoardReadModel $board): Coordinate
    {
        // Target – najpierw kandydaci wokół trafień
        while ($this->targets) {
            $c = array_pop($this->targets);
            if ($this->isLegal($c, $board) && !$this->already($c) && !$board->wasTried($c)) {
                return $this->mark($c);
            }
        }

        $w = $board->width();
        $h = $board->height();

        // Hunt – losowanie z opcjonalnym filtrem „szachownicy”
        $useCheckerboard = $this->shouldUseCheckerboard($w, $h);
        $maxAttempts = max(256, $w * $h * 2);

        for ($attempt = 0; $attempt < $maxAttempts; ++$attempt) {
            $x = random_int(0, $w - 1);
            $y = random_int(0, $h - 1);

            if ($useCheckerboard && (($x + $y) % 2) !== ($this->checkerboardOffset % 2)) {
                continue;
            }

            $c = new Coordinate($x, $y);
            if ($this->already($c) || $board->wasTried($c)) {
                continue;
            }

            return $this->mark($c);
        }

        // Deterministyczny fallback: przeskanuj planszę (najpierw pola zgodne
        // z parzystością filtra, potem wszystkie) – gwarantuje zakończenie.
        foreach ([true, false] as $respectParity) {
            for ($y = 0; $y < $h; ++$y) {
                for ($x = 0; $x < $w; ++$x) {
                    if ($respectParity && $useCheckerboard && (($x + $y) % 2) !== ($this->checkerboardOffset % 2)) {
                        continue;
                    }
                    $c = new Coordinate($x, $y);
                    if (!$this->already($c) && !$board->wasTried($c)) {
                        return $this->mark($c);
                    }
                }
            }
        }

        throw new \DomainException('No untried cells left on board');
    }

    public function notify(Coordinate $c, ShotResult $result): void
    {
        if (ShotResult::Miss === $result || ShotResult::Duplicate === $result) {
            return;
        }

        if (ShotResult::Hit === $result) {
            if (null === $this->firstHit) {
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

    private function shouldUseCheckerboard(int $width, int $height): bool
    {
        return null !== $this->checkerboardMinSize && min($width, $height) >= $this->checkerboardMinSize;
    }

    private function pushNeighbors(Coordinate $c): void
    {
        $this->pushCandidate($c->x + 1, $c->y);
        $this->pushCandidate($c->x - 1, $c->y);
        $this->pushCandidate($c->x, $c->y + 1);
        $this->pushCandidate($c->x, $c->y - 1);
    }

    private function pushLineFrom(Coordinate $a, Coordinate $b): void
    {
        if ($a->x === $b->x) { // pion
            $x = $a->x;
            $this->pushCandidate($x, min($a->y, $b->y) - 1);
            $this->pushCandidate($x, max($a->y, $b->y) + 1);
        } elseif ($a->y === $b->y) { // poziom
            $y = $a->y;
            $this->pushCandidate(min($a->x, $b->x) - 1, $y);
            $this->pushCandidate(max($a->x, $b->x) + 1, $y);
        } else {
            $this->pushNeighbors($b);
        }
    }

    /**
     * Kandydaci przy krawędzi mogą wyjść poza planszę – ujemne odrzucamy od razu
     * (Coordinate nie dopuszcza ujemnych), górne granice filtruje isLegal() w nextShot().
     */
    private function pushCandidate(int $x, int $y): void
    {
        if ($x < 0 || $y < 0) {
            return;
        }
        $this->targets[] = new Coordinate($x, $y);
    }

    private function key(Coordinate $c): string
    {
        return $c->x.':'.$c->y;
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
        return $c->x >= 0 && $c->y >= 0 && $c->x < $b->width() && $c->y < $b->height();
    }
}
