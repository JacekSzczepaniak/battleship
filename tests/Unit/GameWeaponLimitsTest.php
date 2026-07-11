<?php

declare(strict_types=1);

use App\Domain\Game\Area;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use Tests\Support\FleetFactory;

function funGame(): Game
{
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());

    return $game;
}

it('odrzuca torpedę w klasycznym rulesecie', function () {
    $game = Game::create(new ClassicRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());
    $game->fireTorpedo(new Coordinate(0, 0), Direction::E);
})->throws(DomainException::class, 'Torpedo not available in this ruleset');

it('odrzuca sonar w klasycznym rulesecie', function () {
    $game = Game::create(new ClassicRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());
    $game->sonarPing(new Coordinate(5, 5));
})->throws(DomainException::class, 'Sonar not available in this ruleset');

it('egzekwuje limit torped na grę', function () {
    $game = funGame();
    $game->fireTorpedo(new Coordinate(0, 5), Direction::E);
    $game->fireTorpedo(new Coordinate(0, 7), Direction::E);
    $game->fireTorpedo(new Coordinate(0, 9), Direction::E);
})->throws(DomainException::class, 'Torpedo limit reached');

it('egzekwuje limit sonarów na grę', function () {
    $game = funGame();
    $game->sonarPing(new Coordinate(5, 5));
    $game->sonarPing(new Coordinate(2, 2));
    $game->sonarPing(new Coordinate(7, 7));
    $game->sonarPing(new Coordinate(4, 4));
})->throws(DomainException::class, 'Sonar limit reached');

it('egzekwuje limit nalotów na grę', function () {
    $game = funGame();
    $game->sendAirRaid(new Coordinate(5, 5), new Area(1, 1));
    $game->sendAirRaid(new Coordinate(2, 2), new Area(1, 1));
})->throws(DomainException::class, 'AirRaid limit reached');

it('raportuje stan broni: użycia i limity', function () {
    $game = funGame();
    $game->fireTorpedo(new Coordinate(0, 5), Direction::E);
    $game->sonarPing(new Coordinate(5, 5));

    expect($game->weaponsState())->toBe([
        'torpedo' => ['used' => 1, 'limit' => 2],
        'sonar' => ['used' => 1, 'limit' => 3],
        'airRaid' => ['used' => 0, 'limit' => 1],
    ]);
});

it('nieudany nalot (oversize) nie zużywa limitu', function () {
    $game = funGame();
    try {
        $game->sendAirRaid(new Coordinate(5, 5), new Area(3, 3));
    } catch (DomainException) {
        // oversize — oczekiwane
    }

    expect($game->weaponsState()['airRaid']['used'])->toBe(0);
    // limit wciąż dostępny
    $game->sendAirRaid(new Coordinate(5, 5), new Area(1, 1));
    expect($game->weaponsState()['airRaid']['used'])->toBe(1);
});
