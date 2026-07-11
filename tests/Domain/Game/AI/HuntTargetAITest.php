<?php

declare(strict_types=1);

namespace Tests\Domain\Game\AI;

use App\Domain\Game\AI\HuntTargetAI;
use App\Domain\Game\BoardReadModel;
use App\Domain\Game\Coordinate;
use App\Domain\Game\ShotResult;
use PHPUnit\Framework\TestCase;

final class HuntTargetAITest extends TestCase
{
    private function readModel(int $width, array $tried = [], ?int $height = null): BoardReadModel
    {
        return new class($width, $height ?? $width, $tried) implements BoardReadModel {
            public function __construct(private int $width, private int $height, private array $tried)
            {
            }

            public function width(): int
            {
                return $this->width;
            }

            public function height(): int
            {
                return $this->height;
            }

            public function wasTried(Coordinate $c): bool
            {
                return $this->tried[$c->x.':'.$c->y] ?? false;
            }
        };
    }

    public function testGeneratesLegalCoordinatesAndNoDuplicates(): void
    {
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $board = $this->readModel(10);

        $seen = [];
        for ($i = 0; $i < 100; ++$i) {
            $c = $ai->nextShot($board);
            self::assertGreaterThanOrEqual(0, $c->x);
            self::assertGreaterThanOrEqual(0, $c->y);
            self::assertLessThan(10, $c->x);
            self::assertLessThan(10, $c->y);
            $k = $c->x.':'.$c->y;
            self::assertFalse(isset($seen[$k]), "Duplicate coordinate generated: $k");
            $seen[$k] = true;
            // symulujemy MISS, żeby AI szukało dalej
            $ai->notify($c, ShotResult::Miss);
        }
    }

    public function testAddsOrthogonalNeighborsAfterFirstHit(): void
    {
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $board = $this->readModel(5);

        // Wymuś znane trafienie i powiadom
        $hit = new Coordinate(2, 2);
        $ai->notify($hit, ShotResult::Hit);

        // Teraz AI powinno próbować sąsiadów (w dowolnej kolejności), ale zawsze legalnych i nie duplikatów
        $expected = [
            '3:2' => true, '1:2' => true, '2:3' => true, '2:1' => true,
        ];
        $seen = [];
        for ($i = 0; $i < 4; ++$i) {
            $c = $ai->nextShot($board);
            $k = $c->x.':'.$c->y;
            $seen[$k] = true;
            // nie powiadamiamy – chcemy wyssać kolejkę kandydatów
        }

        foreach ($expected as $k => $_) {
            self::assertArrayHasKey($k, $seen, "Expected neighbor $k not proposed");
        }
    }

    public function testExtendsLineAfterSecondHitInSameRowOrColumn(): void
    {
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $board = $this->readModel(10);

        // Trafienia pionowe (2,2) i (2,3) -> powinien dorzucać (2,1) i (2,4) jako kandydatów (lub odpowiednie końce)
        $first = new Coordinate(2, 2);
        $second = new Coordinate(2, 3);
        $ai->notify($first, ShotResult::Hit);
        $ai->notify($second, ShotResult::Hit);

        $candidates = [];
        for ($i = 0; $i < 4; ++$i) {
            $c = $ai->nextShot($board);
            $candidates[$c->x.':'.$c->y] = true;
            // nie powiadamiamy, aby przejrzeć wszystkie bieżące kandydaty
        }

        self::assertTrue(isset($candidates['2:1']) || isset($candidates['2:4']), 'Expected line extension candidates in column 2');
    }

    public function testResetAfterSunk(): void
    {
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $board = $this->readModel(10);

        $hit = new Coordinate(5, 5);
        $ai->notify($hit, ShotResult::Hit);

        // Po SUNK AI powinno wyczyścić kontekst i wrócić do trybu Hunt (losowanie)
        $ai->notify($hit, ShotResult::Sunk);

        $c = $ai->nextShot($board);
        self::assertInstanceOf(Coordinate::class, $c);
    }

    public function testCheckerboardFilterWhenEnabled(): void
    {
        // Włącz „szachownicę” dla N >= 8 i offset = 0
        $ai = new HuntTargetAI(checkerboardMinSize: 8, checkerboardOffset: 0);
        $board = $this->readModel(10);

        // Zbierz kilka strzałów – suma x+y powinna być parzysta (offset 0)
        for ($i = 0; $i < 20; ++$i) {
            $c = $ai->nextShot($board);
            self::assertSame(0, ($c->x + $c->y) % 2, 'Expected checkerboard parity 0');
            $ai->notify($c, ShotResult::Miss);
        }
    }

    public function testRespectsRectangularBoardBounds(): void
    {
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $board = $this->readModel(12, height: 10);

        for ($i = 0; $i < 60; ++$i) {
            $c = $ai->nextShot($board);
            self::assertLessThan(12, $c->x);
            self::assertLessThan(10, $c->y);
            $ai->notify($c, ShotResult::Miss);
        }
    }

    public function testSnapshotRoundTripKeepsTargetQueue(): void
    {
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $hit = new Coordinate(4, 4);
        $ai->notify($hit, ShotResult::Hit);

        $restored = HuntTargetAI::fromSnapshot($ai->toSnapshot(), checkerboardMinSize: null);
        $board = $this->readModel(10, ['4:4' => true]);

        // Odtworzone AI powinno kontynuować dobijanie: 4 sąsiadów trafienia
        $expected = ['5:4' => true, '3:4' => true, '4:5' => true, '4:3' => true];
        for ($i = 0; $i < 4; ++$i) {
            $c = $restored->nextShot($board);
            $k = $c->x.':'.$c->y;
            self::assertArrayHasKey($k, $expected, "Unexpected candidate $k after restore");
            unset($expected[$k]);
        }
        self::assertSame([], $expected, 'Not all neighbors proposed after restore');
    }

    public function testNeverRepeatsBoardTriedCellsAfterRestore(): void
    {
        // Świeże AI (pusty snapshot – jak po odtworzeniu starego/nieznanego kształtu),
        // plansza pamięta wcześniejsze strzały – AI nie może ich powtórzyć.
        $tried = [];
        foreach ([[0, 0], [1, 0], [2, 0], [3, 0]] as [$x, $y]) {
            $tried["$x:$y"] = true;
        }
        $ai = HuntTargetAI::fromSnapshot(['mode' => 'target', 'queue' => [['x' => 0, 'y' => 0]]], checkerboardMinSize: null);
        $board = $this->readModel(3, $tried, height: 3);

        $seen = [];
        for ($i = 0; $i < 6; ++$i) { // 9 pól - 3 ostrzelane w granicach planszy = 6 wolnych
            $c = $ai->nextShot($board);
            $k = $c->x.':'.$c->y;
            self::assertArrayNotHasKey($k, $tried, "AI repeated an already tried cell: $k");
            self::assertArrayNotHasKey($k, $seen, "AI repeated its own shot: $k");
            $seen[$k] = true;
            $ai->notify($c, ShotResult::Miss);
        }
    }

    public function testHitAtBoardCornerDoesNotProduceNegativeCandidates(): void
    {
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $board = $this->readModel(10, ['0:0' => true]);

        // Trafienie w rogu (0,0) – kandydaci mogą być tylko (1,0) i (0,1)
        $ai->notify(new Coordinate(0, 0), ShotResult::Hit);

        $allowed = ['1:0' => true, '0:1' => true];
        for ($i = 0; $i < 2; ++$i) {
            $c = $ai->nextShot($board);
            self::assertArrayHasKey($c->x.':'.$c->y, $allowed);
        }
    }

    public function testThrowsWhenBoardExhausted(): void
    {
        $tried = [];
        for ($x = 0; $x < 2; ++$x) {
            for ($y = 0; $y < 2; ++$y) {
                $tried["$x:$y"] = true;
            }
        }
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $board = $this->readModel(2, $tried);

        $this->expectException(\DomainException::class);
        $ai->nextShot($board);
    }
}
