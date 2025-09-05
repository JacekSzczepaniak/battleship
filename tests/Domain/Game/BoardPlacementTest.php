<?php


declare(strict_types=1);

namespace Tests\Domain\Game;

use App\Domain\Game\Board;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use PHPUnit\Framework\TestCase;

final class BoardPlacementTest extends TestCase
{
    public function testRejectsOverlap(): void
    {
        $board = new Board(new BoardSize(10, 10));
        $a = new Ship(new Coordinate(2, 2), Orientation::H, 3); // (2,2),(3,2),(4,2)
        $b = new Ship(new Coordinate(3, 2), Orientation::V, 2); // (3,2),(3,3) – nachodzi

        $board->place($a);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ships overlap or touch');
        $board->place($b);
    }

    public function testRejectsSideTouch(): void
    {
        $board = new Board(new BoardSize(10, 10));
        $a = new Ship(new Coordinate(2, 2), Orientation::H, 3); // (2,2),(3,2),(4,2)
        $b = new Ship(new Coordinate(2, 3), Orientation::H, 2); // (2,3),(3,3) – dotyka bokiem

        $board->place($a);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ships overlap or touch');
        $board->place($b);
    }

    public function testRejectsDiagonalTouch(): void
    {
        $board = new Board(new BoardSize(10, 10));
        $a = new Ship(new Coordinate(2, 2), Orientation::H, 2); // (2,2),(3,2)
        $b = new Ship(new Coordinate(4, 3), Orientation::V, 1); // (4,3) – po skosie do (3,2)? Nie, to nie skos. Zmieńmy:
        $b = new Ship(new Coordinate(3, 3), Orientation::V, 1); // (3,3) – skos do (2,2) i (3,2)

        $board->place($a);
        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Ships overlap or touch');
        $board->place($b);
    }

    public function testAllowsSeparatedShips(): void
    {
        $board = new Board(new BoardSize(10, 10));
        $a = new Ship(new Coordinate(1, 1), Orientation::H, 3); // (1,1),(2,1),(3,1)
        $b = new Ship(new Coordinate(1, 3), Orientation::H, 2); // (1,3),(2,3) – odstęp 1 w pionie (puste (1,2),(2,2),(3,2))

        $board->place($a);
        $board->place($b);

        self::assertCount(2, $board->ships());
    }

    public function testPlaceManyValidatesAll(): void
    {
        $board = new Board(new BoardSize(10, 10));
        $ships = [
            new Ship(new Coordinate(0, 0), Orientation::H, 4),
            new Ship(new Coordinate(0, 2), Orientation::H, 3),
            new Ship(new Coordinate(6, 0), Orientation::V, 3),
            new Ship(new Coordinate(5, 4), Orientation::H, 2),
            new Ship(new Coordinate(9, 0), Orientation::V, 2),
        ];

        $board->placeMany($ships);
        self::assertCount(5, $board->ships());
    }
}
