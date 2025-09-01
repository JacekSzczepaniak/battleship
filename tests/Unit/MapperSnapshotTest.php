<?php

declare(strict_types=1);

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Game;
use App\Domain\Game\GameStatus;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;

it('mapuje Game -> array -> Game (round-trip) z flotą', function () {
    $g = Game::create(new ClassicRuleset(new BoardSize(12, 10)));
    $ships = [
        new Ship(new Coordinate(0, 0), Orientation::H, 4),
        new Ship(new Coordinate(0, 2), Orientation::H, 3),
        new Ship(new Coordinate(5, 2), Orientation::V, 3),
        new Ship(new Coordinate(8, 0), Orientation::V, 2),
        new Ship(new Coordinate(10, 5), Orientation::H, 2),
        new Ship(new Coordinate(2, 7), Orientation::H, 2),
        new Ship(new Coordinate(0, 5), Orientation::H, 1),
        new Ship(new Coordinate(5, 7), Orientation::H, 1), // uwaga: 5,7 (nie 4,7)
        new Ship(new Coordinate(7, 7), Orientation::H, 1),
        new Ship(new Coordinate(11, 9), Orientation::H, 1),
    ];
    $g->placeFleet($ships);

    $m = new GameSnapshotMapper();
    $arr = $m->toArray($g);
    expect($arr['status'])->toBe('in_progress');

    $g2 = $m->toDomain((string) $g->id(), $arr);
    expect($g2->status())->toBe(GameStatus::InProgress);
    expect($g2->ruleset()->boardSize()->width)->toBe(12);
    expect($g2->fleet())->not->toBeNull();
});
