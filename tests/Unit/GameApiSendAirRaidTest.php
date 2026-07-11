<?php

use App\Domain\Game\Area;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use Tests\Support\FleetFactory;

// nalot jest dozwolony na obszar max 3x3 (Area = pół-zasięgi od centrum)

it('pozwala przeprowadzić nalot na wybrany obszar', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10()); // ← najważniejsze: pełna, poprawna flota
    $centralPoint = new Coordinate(2, 2);

    // tablica 3x3
    self::assertSame(
        [
            // pierwszy rząd
            ['x' => 1, 'y' => 1, 'result' => 'miss'],
            ['x' => 1, 'y' => 2, 'result' => 'hit'],
            ['x' => 1, 'y' => 3, 'result' => 'miss'],
            // drugi
            ['x' => 2, 'y' => 1, 'result' => 'miss'],
            ['x' => 2, 'y' => 2, 'result' => 'hit'],
            ['x' => 2, 'y' => 3, 'result' => 'miss'],
            // trzeci
            ['x' => 3, 'y' => 1, 'result' => 'miss'],
            ['x' => 3, 'y' => 2, 'result' => 'miss'],
            ['x' => 3, 'y' => 3, 'result' => 'miss'],
        ],
        $game->sendAirRaid($centralPoint, new Area(1, 1))
    );
});

it('pozwala przeprowadzić nalot na wybrany obszar przy narożnikach', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10()); // ← najważniejsze: pełna, poprawna flota

    // Centrum (1,1): obszar przycięty do 0..2 × 0..2 — wiersz i kolumna 0 WCHODZĄ w nalot.
    // Trafienia: czteromasztowiec (0,0)-(3,0) i trójmasztowiec (0,2)-(2,2);
    // (2,2) domyka trójmasztowiec → sunk.
    $centralPoint = new Coordinate(1, 1);
    self::assertSame(
        [
            ['x' => 0, 'y' => 0, 'result' => 'hit'],
            ['x' => 0, 'y' => 1, 'result' => 'miss'],
            ['x' => 0, 'y' => 2, 'result' => 'hit'],
            ['x' => 1, 'y' => 0, 'result' => 'hit'],
            ['x' => 1, 'y' => 1, 'result' => 'miss'],
            ['x' => 1, 'y' => 2, 'result' => 'hit'],
            ['x' => 2, 'y' => 0, 'result' => 'hit'],
            ['x' => 2, 'y' => 1, 'result' => 'miss'],
            ['x' => 2, 'y' => 2, 'result' => 'sunk'],
        ],
        $game->sendAirRaid($centralPoint, new Area(1, 1))
    );

    // limit nalotów to 1 na grę — druga krawędź w świeżej grze
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());
    $centralPoint = new Coordinate(9, 5);

    self::assertSame(
        [
            ['x' => 8, 'y' => 4, 'result' => 'miss'],
            ['x' => 8, 'y' => 5, 'result' => 'miss'],
            ['x' => 8, 'y' => 6, 'result' => 'miss'],
            ['x' => 9, 'y' => 4, 'result' => 'miss'],
            ['x' => 9, 'y' => 5, 'result' => 'miss'],
            ['x' => 9, 'y' => 6, 'result' => 'miss'],
        ],
        $game->sendAirRaid($centralPoint, new Area(1, 1))
    );
});

it('obejmuje pole (0,0) przy nalocie w sam róg planszy', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());

    self::assertSame(
        [
            ['x' => 0, 'y' => 0, 'result' => 'hit'],
            ['x' => 0, 'y' => 1, 'result' => 'miss'],
            ['x' => 1, 'y' => 0, 'result' => 'hit'],
            ['x' => 1, 'y' => 1, 'result' => 'miss'],
        ],
        $game->sendAirRaid(new Coordinate(0, 0), new Area(1, 1))
    );
});

it('mapuje width na oś x, a height na oś y', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());

    // height=1 rozciąga obszar w osi y: kolumna x=5, y od 4 do 6.
    // (5,4) to część dwumasztowca (5,4)-(6,4) → hit; przy zamienionych osiach byłyby same pudła.
    self::assertSame(
        [
            ['x' => 5, 'y' => 4, 'result' => 'hit'],
            ['x' => 5, 'y' => 5, 'result' => 'miss'],
            ['x' => 5, 'y' => 6, 'result' => 'miss'],
        ],
        $game->sendAirRaid(new Coordinate(5, 5), new Area(0, 1))
    );
});

it('nie pozwala na zbyt duży nalot', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10()); // ← najważniejsze: pełna, poprawna flota
    $centralPoint = new Coordinate(5, 5);
    $game->sendAirRaid($centralPoint, new Area(3, 3));
})->throws(DomainException::class);

it('nie pozwala na zbyt duży nalot także przy krawędzi (przycięcie nie maskuje rozmiaru)', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());
    // żądany obszar 5x5 > limit 3x3, mimo że po przycięciu do planszy byłby mały
    $game->sendAirRaid(new Coordinate(0, 0), new Area(2, 2));
})->throws(DomainException::class, 'Air Raid area is oversize');

it('odrzuca start nalotu poza planszą', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());
    $game->sendAirRaid(new Coordinate(10, 5), new Area(1, 1));
})->throws(DomainException::class, 'Air Raid start outside board');
