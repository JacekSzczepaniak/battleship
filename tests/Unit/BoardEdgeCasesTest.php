<?php

declare(strict_types=1);

use App\Domain\Game\Board;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;

it('pozwala stawiać tuż przy krawędziach bez styku', function () {
    $b = new Board(new BoardSize(5, 5));
    $b->place(new Ship(new Coordinate(0, 0), Orientation::H, 2)); // lewa/górna krawędź
    $b->place(new Ship(new Coordinate(3, 4), Orientation::H, 2)); // prawa/dolna krawędź
    expect($b->ships())->toHaveCount(2);
});

it('odrzuca stykanie się po skosie na rogach', function () {
    $b = new Board(new BoardSize(5, 5));
    $b->place(new Ship(new Coordinate(0, 0), Orientation::H, 2));
    $b->place(new Ship(new Coordinate(1, 1), Orientation::H, 1)); // dotyka po skosie
})->throws(DomainException::class);

it('odrzuca ujemne współrzędne (już na etapie VO)', function () {
    new Coordinate(-1, 0);
})->throws(InvalidArgumentException::class);
