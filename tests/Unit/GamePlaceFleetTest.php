<?php

declare(strict_types=1);

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Game;
use App\Domain\Game\GameStatus;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;

it('akceptuje klasyczny zestaw 1x4, 2x3, 3x2, 4x1', function () {
    $g = Game::create(new ClassicRuleset(new BoardSize(12, 10)));
    $ships = [
        new Ship(new Coordinate(0, 0), Orientation::H, 4),
        new Ship(new Coordinate(0, 2), Orientation::H, 3),
        new Ship(new Coordinate(5, 2), Orientation::V, 3),
        new Ship(new Coordinate(8, 0), Orientation::V, 2),
        new Ship(new Coordinate(10, 5), Orientation::H, 2),
        new Ship(new Coordinate(2, 7), Orientation::H, 2),
        new Ship(new Coordinate(0, 5), Orientation::H, 1),
        new Ship(new Coordinate(5, 7), Orientation::H, 1),
        new Ship(new Coordinate(7, 7), Orientation::H, 1),
        new Ship(new Coordinate(11, 9), Orientation::H, 1),
    ];
    $g->placeFleet($ships);
    expect($g->status())->toBe(GameStatus::InProgress);
});

it('odrzuca błędną kompozycję floty', function () {
    $g = Game::create(new ClassicRuleset(new BoardSize(10, 10)));
    $ships = [new Ship(new Coordinate(0, 0), Orientation::H, 4)];
    $g->placeFleet($ships);
})->throws(DomainException::class);
