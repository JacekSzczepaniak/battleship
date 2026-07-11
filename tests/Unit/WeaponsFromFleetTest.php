<?php

declare(strict_types=1);

use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use Tests\Support\FleetFactory;

// Broń wynika ze składu floty: jedno użycie na nośnik
// (torpeda=niszczyciel l3, sonar=kuter l2, nalot=lotniskowiec l4).

it('limity broni wynikają ze składu floty', function () {
    // flota wyprawy: kuter + 3 tratwy → tylko 1 sonar, zero torped i nalotów
    $weapons = (new FunRuleset(new BoardSize(7, 7), [2 => 1, 1 => 3]))->weapons()->limits();

    expect($weapons)->toBe(['torpedo' => 0, 'sonar' => 1, 'airRaid' => 0, 'torpedoDiagonal' => 0]);
});

it('flota klasyczna zachowuje dotychczasowe limity', function () {
    expect((new FunRuleset())->weapons()->limits())
        ->toBe(['torpedo' => 2, 'sonar' => 3, 'airRaid' => 1, 'torpedoDiagonal' => 1]);
});

it('zatopienie wszystkich kutrów odbiera sonar do końca bitwy', function () {
    $game = Game::create(new FunRuleset());
    $game->placeFleet(FleetFactory::classic10x10());

    // kutry (l2) w klasycznym układzie: (5,4)-(6,4) H, (9,0)-(9,1) V, (3,6)-(3,7) V
    foreach ([[5, 4], [6, 4], [9, 0], [9, 1], [3, 6], [3, 7]] as [$x, $y]) {
        $game->fireOpponentShot(new Coordinate($x, $y));
    }

    expect(fn () => $game->sonarPing(new Coordinate(5, 5)))
        ->toThrow(DomainException::class, 'Sonar requires an unsunk scout ship');
    // nieudany ping nie zużywa limitu
    expect($game->weaponsState()['sonar']['used'])->toBe(0);
});

it('zatopienie lotniskowca odbiera nalot do końca bitwy', function () {
    $game = Game::create(new FunRuleset());
    $game->placeFleet(FleetFactory::classic10x10());

    // lotniskowiec (l4): (0,0)-(3,0)
    foreach ([[0, 0], [1, 0], [2, 0], [3, 0]] as [$x, $y]) {
        $game->fireOpponentShot(new Coordinate($x, $y));
    }

    expect(fn () => $game->sendAirRaid(new Coordinate(5, 5), new App\Domain\Game\Area(1, 1)))
        ->toThrow(DomainException::class, 'Air raid requires an unsunk carrier');
});

it('legalne wyrzutnie torped AI to wyłącznie komórki jego niszczycieli', function () {
    $game = Game::create(new FunRuleset());
    $game->placeFleet(FleetFactory::classic10x10());
    $game->placeOpponentFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 4),
        new Ship(new Coordinate(0, 2), Orientation::H, 3),
        new Ship(new Coordinate(6, 0), Orientation::V, 3),
        new Ship(new Coordinate(5, 4), Orientation::H, 2),
        new Ship(new Coordinate(9, 0), Orientation::V, 2),
        new Ship(new Coordinate(3, 6), Orientation::V, 2),
        new Ship(new Coordinate(0, 6), Orientation::H, 1),
        new Ship(new Coordinate(1, 8), Orientation::H, 1),
        new Ship(new Coordinate(5, 9), Orientation::H, 1),
        new Ship(new Coordinate(8, 8), Orientation::H, 1),
    ]);

    $cells = $game->opponentLaunchableCells();

    // dwa niszczyciele × 3 komórki
    expect($cells)->toHaveCount(6)
        ->and($cells)->toContain(['x' => 0, 'y' => 2])
        ->and($cells)->not->toContain(['x' => 0, 'y' => 0]); // lotniskowiec to nie wyrzutnia
});

it('torpeda floty wyprawy bez niszczyciela jest niedostępna', function () {
    // kuter + 3 tratwy: limit torped 0 → 'not available'
    $game = Game::create(new FunRuleset(new BoardSize(7, 7), [2 => 1, 1 => 3]));
    $game->placeFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 2),
        new Ship(new Coordinate(4, 0), Orientation::H, 1),
        new Ship(new Coordinate(6, 2), Orientation::H, 1),
        new Ship(new Coordinate(0, 4), Orientation::H, 1),
    ]);

    $game->fireTorpedo(new Coordinate(0, 0), Direction::E);
})->throws(DomainException::class, 'Torpedo not available in this ruleset');
