<?php


declare(strict_types=1);

namespace Tests\Domain\Game;

use App\Domain\Game\BoardReadModel;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Shooter;
use App\Domain\Game\ShotResult;
use App\Domain\Game\TargetBoard;
use App\Domain\Game\TurnLoop;
use PHPUnit\Framework\TestCase;

final class TurnLoopTest extends TestCase
{
    private function stubShooter(array $shotsAndResultsPerTurn): Shooter
    {
        // ... existing code ...
        return new class($shotsAndResultsPerTurn) implements Shooter {
            private int $turnIdx = 0;
            private int $shotIdx = 0;
            /** @var array<int,array<int,array{c:array{0:int,1:int},r:ShotResult}>> */
            public function __construct(private array $plan) {}

            public function nextShot(BoardReadModel $board): Coordinate
            {
                $entry = $this->plan[$this->turnIdx][$this->shotIdx] ?? null;
                if ($entry === null) {
                    return new Coordinate(0, 0);
                }
                return new Coordinate($entry['c'][0], $entry['c'][1]);
            }

            public function notify(Coordinate $c, ShotResult $result): void
            {
                $this->shotIdx++;
                $entry = $this->plan[$this->turnIdx][$this->shotIdx - 1] ?? null;

                // koniec tury po MISS lub DUPLICATE
                if ($entry && ($entry['r'] === ShotResult::Miss || $entry['r'] === ShotResult::Duplicate)) {
                    $this->turnIdx++;
                    $this->shotIdx = 0;
                }
            }
        };
    }

    private function stubBoard(array $results, int $size = 10): TargetBoard
    {
        // results – sekwencja wyników zwracanych przez kolejne shoot()
        return new class($results, $size) implements TargetBoard {
            private int $i = -1;
            public function __construct(private array $results, private int $size) {}
            public function size(): int { return $this->size; }
            public function wasTried(Coordinate $c): bool { return false; }
            public function shoot(Coordinate $c): ShotResult {
                $this->i++;
                return $this->results[$this->i] ?? ShotResult::Miss;
            }
            public function isDefeated(): bool {
                if ($this->i < 0) {
                    return false;
                }
                return ($this->results[$this->i] ?? null) === ShotResult::Sunk;
            }
        };
    }

    public function testTurnSwitchesOnMissAndContinuesOnHitOrSunk(): void
    {
        // Zamykamy partię po kilku turach, aby play() zwrócił zwycięzcę
        // P1: Hit, Hit, Miss | P2: Miss | P1: Sunk => wygrywa P1
        $p2Board = $this->stubBoard([
            ShotResult::Hit, ShotResult::Hit, ShotResult::Miss, // tura P1
            ShotResult::Miss,                                  // tura P2
            ShotResult::Sunk,                                  // tura P1 – zwycięstwo
        ]);
        $p1Board = $this->stubBoard([ShotResult::Miss]); // nieistotne, P2 strzela raz

        $p1 = $this->stubShooter([
            [ ['c'=>[0,0], 'r'=>ShotResult::Hit], ['c'=>[0,1], 'r'=>ShotResult::Hit], ['c'=>[0,2], 'r'=>ShotResult::Miss] ],
            [ ['c'=>[0,3], 'r'=>ShotResult::Sunk] ],
        ]);
        $p2 = $this->stubShooter([
            [ ['c'=>[1,1], 'r'=>ShotResult::Miss] ],
        ]);

        $loop = new TurnLoop($p1, $p2Board, $p2, $p1Board);
        $winner = $loop->play();

        self::assertSame(1, $winner);
    }

    public function testWinnerReturnedWhenBoardDefeated(): void
    {
        // ... existing code ...
        $p2Board = $this->stubBoard([ShotResult::Sunk]);
        $p1Board = $this->stubBoard([ShotResult::Miss]);

        $p1 = $this->stubShooter([[ ['c'=>[1,1], 'r'=>ShotResult::Sunk] ]]);
        $p2 = $this->stubShooter([[ ['c'=>[2,2], 'r'=>ShotResult::Miss] ]]);

        $loop = new TurnLoop($p1, $p2Board, $p2, $p1Board);
        $winner = $loop->play();

        self::assertSame(1, $winner);
    }

    public function testTurnEndsOnDuplicate(): void
    {
        $p1Board = $this->stubBoard([ShotResult::Duplicate, ShotResult::Miss]);
        $p2Board = $this->stubBoard([ShotResult::Miss, ShotResult::Sunk]);

        $p1 = $this->stubShooter([
            [ ['c'=>[0,0], 'r'=>ShotResult::Duplicate] ], // tura 1
            [ ['c'=>[0,1], 'r'=>ShotResult::Miss] ],      // tura 3
        ]);
        $p2 = $this->stubShooter([
            [ ['c'=>[1,1], 'r'=>ShotResult::Miss] ],      // tura 2
            [ ['c'=>[1,2], 'r'=>ShotResult::Sunk] ],      // tura 4 – zwycięstwo P2
        ]);

        $loop = new TurnLoop($p1, $p1Board, $p2, $p2Board);
        $winner = $loop->play();

        self::assertSame(2, $winner);
    }
}
