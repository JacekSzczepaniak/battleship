<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\WorldMap;

const SEA_ISLANDS = ['zatoka-rozbitka', 'mielizny', 'wyspa-sygnalowa', 'archipelag-mgiel', 'ciesnina-sztormow', 'twierdza-admiralicji'];

function seaWorldFor(CaptainProfile $profile): WorldMap
{
    return WorldMap::generate($profile->worldSeed(), SEA_ISLANDS);
}

it('ten sam seed generuje ten sam świat, inny seed — inny', function () {
    $a = WorldMap::generate(123, SEA_ISLANDS);
    $b = WorldMap::generate(123, SEA_ISLANDS);
    $c = WorldMap::generate(456, SEA_ISLANDS);

    expect($a->islands())->toBe($b->islands())
        ->and($a->islands())->not->toBe($c->islands());
});

it('wyspy trasy leżą w kolejnych pasmach coraz dalej od startu', function () {
    $world = WorldMap::generate(2026, SEA_ISLANDS);

    $previousBand = -1;
    foreach (SEA_ISLANDS as $i => $id) {
        $pos = $world->islandPosition($id);
        expect($world->isInside($pos['x'], $pos['y']))->toBeTrue()
            ->and(intdiv($pos['x'], 2))->toBe($i)
            ->and(intdiv($pos['x'], 2))->toBeGreaterThan($previousBand);
        $previousBand = intdiv($pos['x'], 2);
    }
});

it('start wyprawy to sektor pierwszej wyspy z odkrytym sąsiedztwem', function () {
    $profile = CaptainProfile::create('Jacek');
    $world = seaWorldFor($profile);
    $profile->ensureWorldState($world);

    $start = $world->islandPosition('zatoka-rozbitka');

    expect($profile->position())->toBe($start)
        ->and($profile->isDiscovered($start['x'], $start['y']))->toBeTrue()
        ->and($profile->isAt($world, 'zatoka-rozbitka'))->toBeTrue();
});

it('żegluga tylko na sąsiedni sektor', function () {
    $profile = CaptainProfile::create('Jacek');
    $world = seaWorldFor($profile);
    $profile->ensureWorldState($world);
    $pos = $profile->position();

    $farX = $pos['x'] > WorldMap::WIDTH / 2 ? 0 : WorldMap::WIDTH - 1;
    $profile->sail($farX, $pos['y'], $world);
})->throws(DomainException::class, 'Can only sail to an adjacent sector');

it('żegluga odkrywa sąsiedztwo i płaci za kartografię', function () {
    $profile = CaptainProfile::create('Jacek');
    $world = seaWorldFor($profile);
    $profile->ensureWorldState($world);

    $pos = $profile->position();
    $target = ['x' => $pos['x'] + ($pos['x'] < WorldMap::WIDTH - 1 ? 1 : -1), 'y' => $pos['y']];
    $materialsBefore = $profile->materials();

    $outcome = $profile->sail($target['x'], $target['y'], $world);

    expect($profile->position())->toBe($target)
        ->and($profile->moveCount())->toBe(1);

    // materiały nie spadają poniżej: przed + kartografia - ewentualny sztorm (max 5)
    $expected = $materialsBefore + $outcome['cartography']
        - ('materials-lost' === ($outcome['event']['effect'] ?? null) ? $outcome['event']['materials'] : 0);
    expect($profile->materials())->toBe($expected);

    foreach ($outcome['discoveredNow'] as $sector) {
        [$x, $y] = array_map('intval', explode(':', $sector));
        expect($profile->isDiscovered($x, $y))->toBeTrue();
    }
});

it('sztormy są deterministyczne — bliźniacze profile mają ten sam los', function () {
    $a = CaptainProfile::create('Jacek');
    $world = seaWorldFor($a);
    $b = CaptainProfile::fromSnapshot($a->id(), 'Jacek', 0, [], null, null, $a->worldSeed());

    $a->ensureWorldState($world);
    $b->ensureWorldState($world);

    // ta sama trasa — 5 ruchów wężykiem w prawo/lewo od startu
    $route = [];
    $pos = $a->position();
    for ($i = 0; $i < 5; ++$i) {
        $pos = ['x' => $pos['x'] + ($pos['x'] < WorldMap::WIDTH - 1 ? 1 : -1), 'y' => $pos['y']];
        $route[] = $pos;
    }

    foreach ($route as $step) {
        $eventA = $a->sail($step['x'], $step['y'], $world)['event'];
        $eventB = $b->sail($step['x'], $step['y'], $world)['event'];
        expect($eventA)->toBe($eventB);
    }
});

it('na zbadanych wodach nie ma sztormów', function () {
    $profile = CaptainProfile::create('Jacek');
    $world = seaWorldFor($profile);
    $profile->ensureWorldState($world);

    $start = $profile->position();
    $target = ['x' => $start['x'] + ($start['x'] < WorldMap::WIDTH - 1 ? 1 : -1), 'y' => $start['y']];

    $profile->sail($target['x'], $target['y'], $world);
    // powrót po własnych śladach — sektor startowy jest odkryty
    $back = $profile->sail($start['x'], $start['y'], $world);

    expect($back['event'])->toBeNull()
        ->and($back['cartography'])->toBe(0);
});

it('profil sprzed wolnego morza dostaje pozycję startową przy inicjalizacji', function () {
    $profile = CaptainProfile::create('Jacek');
    $legacy = CaptainProfile::fromSnapshot($profile->id(), 'Jacek', 100, []);
    $world = seaWorldFor($legacy);

    expect($legacy->hasWorldState())->toBeFalse()
        ->and($legacy->worldSeed())->toBe($profile->worldSeed());

    $legacy->ensureWorldState($world);

    expect($legacy->position())->toBe($world->islandPosition('zatoka-rozbitka'));
});

it('stan morski round-tripuje przez snapshot', function () {
    $profile = CaptainProfile::create('Jacek');
    $world = seaWorldFor($profile);
    $profile->ensureWorldState($world);
    $pos = $profile->position();
    $profile->sail($pos['x'] + ($pos['x'] < WorldMap::WIDTH - 1 ? 1 : -1), $pos['y'], $world);

    $restored = CaptainProfile::fromSnapshot(
        $profile->id(), 'Jacek', 0, [], $profile->fleet(), $profile->materials(),
        $profile->worldSeed(), $profile->position(), $profile->discoveredSectors(), $profile->moveCount(),
    );

    expect($restored->position())->toBe($profile->position())
        ->and($restored->moveCount())->toBe($profile->moveCount())
        ->and(sort_sectors($restored->discoveredSectors()))->toBe(sort_sectors($profile->discoveredSectors()));
});

/** @param list<string> $sectors */
function sort_sectors(array $sectors): array
{
    sort($sectors);

    return $sectors;
}
