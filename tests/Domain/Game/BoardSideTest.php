<?php

declare(strict_types=1);

namespace Tests\Domain\Game;

use App\Domain\Game\BoardSide;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Game\ShotResult;
use PHPUnit\Framework\TestCase;

final class BoardSideTest extends TestCase
{
    private function sideWithTwoMastShip(): BoardSide
    {
        $side = new BoardSide(new BoardSize(10, 10));
        $side->placeFleet([new Ship(new Coordinate(2, 2), Orientation::H, 2)]);

        return $side;
    }

    public function testReceiveShotMissHitSunkAndDuplicate(): void
    {
        $side = $this->sideWithTwoMastShip();

        self::assertSame(ShotResult::Miss, $side->receiveShot(new Coordinate(0, 0)));
        self::assertSame(ShotResult::Hit, $side->receiveShot(new Coordinate(2, 2)));
        self::assertSame(ShotResult::Duplicate, $side->receiveShot(new Coordinate(2, 2)));
        self::assertFalse($side->allShipsHit());
        self::assertSame(ShotResult::Sunk, $side->receiveShot(new Coordinate(3, 2)));
        self::assertTrue($side->allShipsHit());
    }

    public function testReceiveShotWithoutFleetThrows(): void
    {
        $side = new BoardSide(new BoardSize(10, 10));

        $this->expectException(\DomainException::class);
        $side->receiveShot(new Coordinate(0, 0));
    }

    public function testPlaceFleetTwiceThrows(): void
    {
        $side = $this->sideWithTwoMastShip();

        $this->expectException(\DomainException::class);
        $side->placeFleet([new Ship(new Coordinate(5, 5), Orientation::H, 2)]);
    }

    public function testShotsWithResultsReflectSunkState(): void
    {
        $side = $this->sideWithTwoMastShip();
        $side->receiveShot(new Coordinate(2, 2)); // hit
        $side->receiveShot(new Coordinate(0, 0)); // miss

        self::assertSame([
            ['x' => 2, 'y' => 2, 'result' => 'hit'],
            ['x' => 0, 'y' => 0, 'result' => 'miss'],
        ], $side->shotsWithResults());

        $side->receiveShot(new Coordinate(3, 2)); // sunk — oba trafienia raportują sunk

        self::assertSame([
            ['x' => 2, 'y' => 2, 'result' => 'sunk'],
            ['x' => 0, 'y' => 0, 'result' => 'miss'],
            ['x' => 3, 'y' => 2, 'result' => 'sunk'],
        ], $side->shotsWithResults());
    }

    public function testSnapshotRoundTripRestoresShotsBeforeFleet(): void
    {
        // Kolejność jak przy odtwarzaniu z repozytorium: strzały mogą przyjść
        // przed flotą (flagi 'r' wystarczają) albo po niej — wynik ten sam.
        $side = new BoardSide(new BoardSize(10, 10));
        $side->setShotsFromSnapshot([
            ['x' => 2, 'y' => 2, 'r' => 'hit'],
            ['x' => 0, 'y' => 0, 'r' => 'miss'],
        ]);
        $side->setFleetFromSnapshot([new Ship(new Coordinate(2, 2), Orientation::H, 2)]);

        self::assertSame(ShotResult::Duplicate, $side->receiveShot(new Coordinate(2, 2)));
        self::assertSame(ShotResult::Sunk, $side->receiveShot(new Coordinate(3, 2)));
        self::assertTrue($side->allShipsHit());
    }

    public function testShotsViewExposesTakenShotsOnly(): void
    {
        $side = $this->sideWithTwoMastShip();
        $side->receiveShot(new Coordinate(4, 4));

        $view = $side->shotsView();
        self::assertTrue($view->wasTried(new Coordinate(4, 4)));
        self::assertFalse($view->wasTried(new Coordinate(2, 2)));
        self::assertSame(10, $view->width());
        self::assertSame(10, $view->height());
    }
}
