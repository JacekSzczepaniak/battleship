<?php

declare(strict_types=1);

use App\Domain\Game\Board;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;

it('pozwala rozstawić statki w granicach i bez styku', function () {
    $b = new Board(new BoardSize(10, 10));
    $b->place(new Ship(new Coordinate(0, 0), Orientation::H, 4));
    $b->place(new Ship(new Coordinate(0, 2), Orientation::H, 3));
    expect($b->ships())->toHaveCount(2);
});

it('odrzuca statek wychodzący poza planszę', function () {
    $b = new Board(new BoardSize(5, 5));
    $b->place(new Ship(new Coordinate(4, 4), Orientation::H, 2));
})->throws(DomainException::class);

it('odrzuca statki stykające się choćby po skosie', function () {
    $b = new Board(new BoardSize(10, 10));
    $b->place(new Ship(new Coordinate(0, 0), Orientation::H, 2));
    // dotknięcie po skosie w (2,1)
    $b->place(new Ship(new Coordinate(1, 1), Orientation::H, 2));
})->throws(DomainException::class);
