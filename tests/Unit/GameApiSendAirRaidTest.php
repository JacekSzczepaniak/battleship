<?php


use App\Domain\Game\Area;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use Tests\Support\FleetFactory;


//nalot jest dozwolony na obszar 3x3

it('pozwala przeprowadzić nalot na wybrany obszar', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10()); // ← najważniejsze: pełna, poprawna flota
    $centralPoint = new Coordinate(2, 2);

    //tablica 3x3
    self::assertSame(
        [
        //pierwszy rząd
        ['x' => 1, 'y' => 1, 'result' => 'miss',],
        ['x' => 1, 'y' => 2, 'result' => 'hit',],
        ['x' => 1, 'y' => 3, 'result' => 'miss',],
        //drugi
        ['x' => 2, 'y' => 1, 'result' => 'miss',],
        ['x' => 2, 'y' => 2, 'result' => 'hit',],
        ['x' => 2, 'y' => 3, 'result' => 'miss',],
        //trzeci
        ['x' => 3, 'y' => 1, 'result' => 'miss',],
        ['x' => 3, 'y' => 2, 'result' => 'miss',],
        ['x' => 3, 'y' => 3, 'result' => 'miss',],
    ],
        $game->sendAirRaid($centralPoint, new Area($centralPoint, 1, 1))
    );
});


it('pozwala przeprowadzić nalot na wybrany obszar przy narożnikach', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10()); // ← najważniejsze: pełna, poprawna flota
    $centralPoint = new Coordinate(1, 1);

    //tablica 2x2
    self::assertSame(
        [
            //pierwszy rząd
            ['x' => 1, 'y' => 1, 'result' => 'miss',],
            ['x' => 1, 'y' => 2, 'result' => 'hit',],
            //drugi
            ['x' => 2, 'y' => 1, 'result' => 'miss',],
            ['x' => 2, 'y' => 2, 'result' => 'hit',],
        ],
        $game->sendAirRaid($centralPoint, new Area($centralPoint, 1, 1))
    );

    $centralPoint = new Coordinate(9, 5);

    self::assertSame(
        [
            ['x' => 8, 'y' => 4, 'result' => 'miss',],
            ['x' => 8, 'y' => 5, 'result' => 'miss',],
            ['x' => 8, 'y' => 6, 'result' => 'miss',],
            ['x' => 9, 'y' => 4, 'result' => 'miss',],
            ['x' => 9, 'y' => 5, 'result' => 'miss',],
            ['x' => 9, 'y' => 6, 'result' => 'miss',],
        ],
        $game->sendAirRaid($centralPoint, new Area($centralPoint, 1, 1))
    );
});

it('nie pozwala na zbyt duży nalot', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10()); // ← najważniejsze: pełna, poprawna flota
    $centralPoint = new Coordinate(5, 5);
    $game->sendAirRaid($centralPoint, new Area($centralPoint, 3, 3));
})->throws(DomainException::class);
