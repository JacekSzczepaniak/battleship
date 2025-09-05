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
    private function readModel(int $size, array $tried = []): BoardReadModel
    {
        return new class($size, $tried) implements BoardReadModel {
            public function __construct(private int $size, private array $tried)
            {
            }

            public function size(): int
            {
                return $this->size;
            }

            public function wasTried(Coordinate $c): bool
            {
                return $this->tried[$c->x . ':' . $c->y] ?? false;
            }
        };
    }

    public function testGeneratesLegalCoordinatesAndNoDuplicates(): void
    {
        $ai = new HuntTargetAI(checkerboardMinSize: null);
        $board = $this->readModel(10);

        $seen = [];
        for ($i = 0; $i < 100; $i++) {
            $c = $ai->nextShot($board);
            self::assertGreaterThanOrEqual(0, $c->x);
            self::assertGreaterThanOrEqual(0, $c->y);
            self::assertLessThan(10, $c->x);
            self::assertLessThan(10, $c->y);
            $k = $c->x . ':' . $c->y;
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
        for ($i = 0; $i < 4; $i++) {
            $c = $ai->nextShot($board);
            $k = $c->x . ':' . $c->y;
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
        for ($i = 0; $i < 4; $i++) {
            $c = $ai->nextShot($board);
            $candidates[$c->x . ':' . $c->y] = true;
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
        for ($i = 0; $i < 20; $i++) {
            $c = $ai->nextShot($board);
            self::assertSame(0, ($c->x + $c->y) % 2, 'Expected checkerboard parity 0');
            $ai->notify($c, ShotResult::Miss);
        }
    }
}
