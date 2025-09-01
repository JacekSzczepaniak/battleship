<?php
declare(strict_types=1);

use App\Domain\Game\{Board, BoardSize, Ship, Orientation, Coordinate};

it("pozwala rozstawić statki w granicach i bez styku", function () {
    $b = new Board(new BoardSize(10,10));
    $b->place(new Ship(new Coordinate(0,0), Orientation::H, 4));
    $b->place(new Ship(new Coordinate(0,2), Orientation::H, 3));
    expect($b->ships())->toHaveCount(2);
});

it("odrzuca statek wychodzący poza planszę", function () {
    $b = new Board(new BoardSize(5,5));
    $b->place(new Ship(new Coordinate(4,4), Orientation::H, 2));
})->throws(DomainException::class);

it("odrzuca statki stykające się choćby po skosie", function () {
    $b = new Board(new BoardSize(10,10));
    $b->place(new Ship(new Coordinate(0,0), Orientation::H, 2));
    // dotknięcie po skosie w (2,1)
    $b->place(new Ship(new Coordinate(1,1), Orientation::H, 2));
})->throws(DomainException::class);
