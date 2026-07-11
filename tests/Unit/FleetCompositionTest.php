<?php

declare(strict_types=1);

use App\Domain\Game\Board;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\DeterministicFleetGenerator;
use App\Domain\Game\FleetComposition;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;

it('ruleset bez składu zwraca flotę klasyczną', function () {
    expect((new ClassicRuleset())->allowedShips())->toBe(FleetComposition::CLASSIC)
        ->and((new FunRuleset())->allowedShips())->toBe(FleetComposition::CLASSIC);
});

it('ruleset przyjmuje niestandardowy skład floty', function () {
    $ruleset = new ClassicRuleset(new BoardSize(10, 10), [2 => 1, 1 => 3]);

    expect($ruleset->allowedShips())->toBe([2 => 1, 1 => 3]);
});

it('odrzuca nieprawidłowy skład floty', function (array $ships) {
    new ClassicRuleset(new BoardSize(10, 10), $ships);
})->with([
    'pusty' => [[]],
    'zerowa liczba sztuk' => [[2 => 0]],
    'za długi statek' => [[7 => 1]],
])->throws(InvalidArgumentException::class);

it('placeFleet waliduje wobec niestandardowego składu', function () {
    $game = Game::create(new ClassicRuleset(new BoardSize(10, 10), [2 => 1, 1 => 2]));

    $game->placeFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 2),
        new Ship(new Coordinate(4, 0), Orientation::H, 1),
        new Ship(new Coordinate(6, 2), Orientation::H, 1),
    ]);

    expect($game->fleet())->toHaveCount(3);
});

it('placeFleet odrzuca flotę klasyczną przy niestandardowym składzie', function () {
    $game = Game::create(new ClassicRuleset(new BoardSize(10, 10), [2 => 1, 1 => 2]));
    $game->placeFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 4),
        new Ship(new Coordinate(0, 2), Orientation::H, 1),
        new Ship(new Coordinate(4, 4), Orientation::H, 1),
    ]);
})->throws(DomainException::class, 'Invalid fleet composition');

it('generator deterministyczny zachowuje stały układ dla floty klasycznej', function () {
    $ships = (new DeterministicFleetGenerator())->generate(new ClassicRuleset());

    expect($ships)->toHaveCount(10)
        ->and($ships[0]->start->x)->toBe(0)
        ->and($ships[0]->start->y)->toBe(0)
        ->and($ships[0]->length)->toBe(4);
});

it('generator deterministyczny układa legalnie niestandardowy skład', function () {
    $ruleset = new FunRuleset(new BoardSize(10, 10), [4 => 1, 3 => 2, 1 => 4]);
    $ships = (new DeterministicFleetGenerator())->generate($ruleset);

    expect($ships)->toHaveCount(7);

    // legalność układu weryfikuje Board (kolizje, styk, granice)
    $board = new Board(new BoardSize(10, 10));
    $board->placeMany($ships);

    // skład zgodny z zamówieniem — waliduje domena
    $game = Game::create($ruleset);
    $game->placeFleet($ships);
    expect($game->fleet())->toHaveCount(7);
});

it('generator deterministyczny rzuca, gdy skład nie mieści się na planszy', function () {
    (new DeterministicFleetGenerator())->generate(
        new ClassicRuleset(new BoardSize(10, 10), [4 => 20])
    );
})->throws(DomainException::class, 'Fleet does not fit on board');

it('snapshot round-tripuje niestandardowy skład floty', function () {
    $mapper = new GameSnapshotMapper();
    $game = Game::create(new FunRuleset(new BoardSize(10, 10), [2 => 2, 1 => 1]));

    // symulacja JSON: klucze mapy stają się stringami
    $state = json_decode((string) json_encode($mapper->toArray($game)), true);
    $restored = $mapper->toDomain((string) $game->id(), $state);

    expect($restored->ruleset()->allowedShips())->toBe([2 => 2, 1 => 1])
        ->and($restored->ruleset()->name())->toBe('fun');
});
