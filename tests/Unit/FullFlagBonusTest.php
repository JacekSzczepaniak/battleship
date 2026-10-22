<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\WorldMap;
use App\Domain\Game\Area;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\DeterministicFleetGenerator;
use App\Domain\Game\FleetComposition;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\GameStatus;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;

// Bonus pełnej bandery (#79): bitwa z asymetrycznym składem floty —
// napastnik (AI) płynie bez swojego największego statku.

it('skład przeciwnika jest domyślnie lustrzany', function () {
    expect((new ClassicRuleset())->opponentShips())->toBe(FleetComposition::CLASSIC)
        ->and((new FunRuleset(new BoardSize(9, 9), [3 => 1, 2 => 1]))->opponentShips())->toBe([3 => 1, 2 => 1]);
});

it('bez największego statku: odpada jeden najdłuższy, flota jednostatkowa zostaje', function (array $ships, array $expected) {
    expect(FleetComposition::withoutLargest($ships))->toBe($expected);
})->with([
    'lotniskowiec' => [[4 => 1, 3 => 1, 2 => 1], [3 => 1, 2 => 1]],
    'jeden z dwóch równych' => [[3 => 2, 1 => 1], [3 => 1, 1 => 1]],
    'same tratwy' => [[1 => 3], [1 => 2]],
    'jeden statek' => [[2 => 1], [2 => 1]],
]);

it('broń przeciwnika wynika z jego własnego składu', function () {
    $ruleset = new FunRuleset(new BoardSize(10, 10), [4 => 1, 3 => 1, 2 => 1], [3 => 1, 2 => 1]);

    expect($ruleset->weapons()->limits())->toBe(['torpedo' => 1, 'sonar' => 1, 'airRaid' => 1, 'torpedoDiagonal' => 1])
        ->and($ruleset->opponentWeapons()->limits())->toBe(['torpedo' => 1, 'sonar' => 1, 'airRaid' => 0, 'torpedoDiagonal' => 1]);
});

it('przy asymetrycznym składzie AI rozstawia flotę według własnego składu', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10), [4 => 1, 3 => 1, 2 => 1], [3 => 1, 2 => 1]));
    $game->placeFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 4),
        new Ship(new Coordinate(0, 2), Orientation::H, 3),
        new Ship(new Coordinate(0, 4), Orientation::H, 2),
    ]);

    $game->placeOpponentFleet((new DeterministicFleetGenerator())->generate($game->ruleset()));

    expect(array_map(static fn (Ship $s) => $s->length, $game->opponentFleet()))->toBe([3, 2])
        ->and($game->opponentWeaponsState()['airRaid'])->toBe(['used' => 0, 'limit' => 0])
        ->and($game->weaponsState()['airRaid'])->toBe(['used' => 0, 'limit' => 1]);
});

it('flota przeciwnika niezgodna z jego składem jest odrzucana', function () {
    $game = Game::create(new ClassicRuleset(new BoardSize(10, 10), [3 => 1, 2 => 1], [2 => 1]));

    // lustrzany skład gracza to za dużo dla napastnika bez największego statku
    $game->placeOpponentFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 3),
        new Ship(new Coordinate(0, 2), Orientation::H, 2),
    ]);
})->throws(DomainException::class, 'Invalid fleet composition');

it('napastnik bez lotniskowca nie ma nalotu', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10), [4 => 1, 2 => 1], [2 => 1]));
    $game->placeFleet([new Ship(new Coordinate(0, 0), Orientation::H, 4), new Ship(new Coordinate(0, 2), Orientation::H, 2)]);
    $game->placeOpponentFleet([new Ship(new Coordinate(5, 5), Orientation::H, 2)]);

    $game->sendOpponentAirRaid(new Coordinate(0, 0), new Area(3, 3));
})->throws(DomainException::class, 'AirRaid not available in this ruleset');

it('bitwa kończy się zwycięstwem po zatopieniu floty napastnika', function () {
    $game = Game::create(new ClassicRuleset(new BoardSize(10, 10), [4 => 1, 2 => 1], [2 => 1]));
    $game->placeFleet([new Ship(new Coordinate(0, 0), Orientation::H, 4), new Ship(new Coordinate(0, 2), Orientation::H, 2)]);
    $game->placeOpponentFleet([new Ship(new Coordinate(5, 5), Orientation::H, 2)]);

    $game->fireShot(new Coordinate(5, 5));
    expect($game->status())->toBe(GameStatus::InProgress);

    $game->fireShot(new Coordinate(6, 5));
    expect($game->status())->toBe(GameStatus::Won);
});

it('snapshot round-tripuje skład przeciwnika, a lustrzany go nie zapisuje', function () {
    $mapper = new GameSnapshotMapper();
    $asymmetric = Game::create(new FunRuleset(new BoardSize(9, 9), [3 => 1, 2 => 1], [2 => 1]));
    $mirrored = Game::create(new FunRuleset(new BoardSize(9, 9), [3 => 1, 2 => 1]));

    // symulacja JSON: klucze mapy stają się stringami
    $state = json_decode((string) json_encode($mapper->toArray($asymmetric)), true);
    $restored = $mapper->toDomain((string) $asymmetric->id(), $state);

    expect($restored->ruleset()->allowedShips())->toBe([3 => 1, 2 => 1])
        ->and($restored->ruleset()->opponentShips())->toBe([2 => 1])
        ->and($mapper->toArray($mirrored)['ruleset']['opponentShips'])->toBeNull();
});

/** Mapa świata z wyspami katalogu (seed jak w testach kontrataków). */
function fullFlagSea(): WorldMap
{
    $islands = (new StaticIslandCatalog())->all();

    return WorldMap::generate(7, array_map(static fn ($i) => $i->id, $islands), $islands);
}

/**
 * Kapitan na Wyspie Sygnałowej z zadanymi banderami i stanem kontrataku.
 *
 * @param array<string, int> $owned
 */
function captainOnSignalIsland(array $owned, int $tick = 0, ?array $counterattacks = null): CaptainProfile
{
    $home = fullFlagSea()->islandPosition('wyspa-sygnalowa');

    return CaptainProfile::fromSnapshot(
        ProfileId::new(), 'Jacek', 0, [], null, Resources::none(), 7, $home,
        ["{$home['x']}:{$home['y']}"], 0, $tick, ["{$home['x']}:{$home['y']}"],
        [], null, [], $owned, $counterattacks,
    );
}

it('pełna bandera to wszystkie wyspy akwenu razem z Zatoką Rozbitka', function () {
    $all = array_fill_keys(array_keys(fullFlagSea()->islands()), 0);
    $withoutHaven = $all;
    unset($withoutHaven['zatoka-rozbitka']);

    expect(count($all))->toBe(6)
        ->and(captainOnSignalIsland($all)->hasFullFlag(fullFlagSea()))->toBeTrue()
        ->and(captainOnSignalIsland($withoutHaven)->hasFullFlag(fullFlagSea()))->toBeFalse()
        ->and(captainOnSignalIsland([])->hasFullFlag(fullFlagSea()))->toBeFalse();
});

it('bonus znika z utratą którejkolwiek wyspy', function () {
    $all = array_fill_keys(array_keys(fullFlagSea()->islands()), 0);
    // termin kontrataku minął bez obrony — ensureWorldState() odbiera wyspę
    $profile = captainOnSignalIsland($all, tick: 32, counterattacks: [
        'pending' => ['island' => 'mielizny', 'announcedAt' => 18, 'strikeAt' => 30, 'defense' => null],
        'calmFrom' => 0, 'rolledTo' => 29, 'last' => null,
    ]);
    expect($profile->hasFullFlag(fullFlagSea()))->toBeTrue();

    $profile->ensureWorldState(fullFlagSea());

    expect($profile->ownsIsland('mielizny'))->toBeFalse()
        ->and($profile->hasFullFlag(fullFlagSea()))->toBeFalse();
});
