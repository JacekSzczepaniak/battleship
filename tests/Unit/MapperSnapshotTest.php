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

it('round-tripuje liczniki broni per strona', function () {
    $g = Game::create(new App\Domain\Game\FunRuleset());
    $g->setWeaponUsesFromSnapshot(['player' => ['torpedo' => 1], 'opponent' => ['sonar' => 2]]);

    $m = new GameSnapshotMapper();
    $g2 = $m->toDomain((string) $g->id(), $m->toArray($g));

    expect($g2->weaponsState()['torpedo']['used'])->toBe(1);
    expect($g2->opponentWeaponsState()['sonar']['used'])->toBe(2);
    expect($g2->opponentWeaponsState()['torpedo']['used'])->toBe(0);
});

it('stary płaski kształt weapons w snapshotcie trafia do liczników gracza', function () {
    $m = new GameSnapshotMapper();
    $g = $m->toDomain('9c2e8a4e-1f2b-4c3d-8a5e-123456789abc', [
        'status' => 'in_progress',
        'ruleset' => ['type' => 'fun', 'board' => ['w' => 10, 'h' => 10]],
        'weapons' => ['torpedo' => 2, 'sonar' => 1],
    ]);

    expect($g->weaponsState()['torpedo']['used'])->toBe(2);
    expect($g->weaponsState()['sonar']['used'])->toBe(1);
    expect($g->opponentWeaponsState()['torpedo']['used'])->toBe(0);
});
