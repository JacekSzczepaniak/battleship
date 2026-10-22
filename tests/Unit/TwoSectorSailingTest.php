<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;

const TWO_SECTOR_ISLANDS = ['zatoka-rozbitka', 'mielizny', 'wyspa-sygnalowa', 'archipelag-mgiel', 'ciesnina-sztormow', 'twierdza-admiralicji'];

/**
 * Kapitan w porcie startowym bez odkrytej mapy (odwiedzony tylko start),
 * plus kierunek, w którym zmieszczą się 3 sektory w poziomie i w pionie.
 *
 * @param list<Technology> $researched
 * @param list<ShipType>   $fleet
 *
 * @return array{0: CaptainProfile, 1: WorldMap, 2: array{x:int,y:int}, 3: int, 4: int}
 */
function navigator(array $researched, int $tick = 0, array $fleet = [ShipType::Kuter], ?Resources $cargo = null, ?int $seed = null): array
{
    $id = CaptainProfile::create('Jacek')->id();
    $seed ??= crc32((string) $id) & 0x7FFFFFFF;
    $world = WorldMap::generate($seed, TWO_SECTOR_ISLANDS);
    $start = $world->startPosition();
    $profile = CaptainProfile::fromSnapshot(
        $id, 'Jacek', 1000, [], array_map(static fn (ShipType $t) => OwnedShip::build($t), $fleet),
        $cargo ?? Resources::none(), $seed, $start, [], tick: $tick, researched: $researched,
    );
    $dx = $start['x'] < WorldMap::WIDTH - 3 ? 1 : -1;
    $dy = $start['y'] < WorldMap::HEIGHT - 3 ? 1 : -1;

    return [$profile, $world, $start, $dx, $dy];
}

it('zasięg rejsu: 1 sektor, z Żeglugą dwusektorową 2', function () {
    [$plain] = navigator([]);
    [$charted] = navigator([Technology::MapySztormowe, Technology::ZeglugaDwusektorowa]);

    expect($plain->sailRange())->toBe(1)
        ->and($charted->sailRange())->toBe(2)
        ->and(Technology::ZeglugaDwusektorowa->level()->value)->toBe(2);
});

it('bez technologii rejs o 2 jest odrzucany i nie kosztuje tiku', function () {
    [$profile, $world, $start, $dx] = navigator([Technology::MapySztormowe]);

    expect(fn () => $profile->sail($start['x'] + 2 * $dx, $start['y'], $world))
        ->toThrow(DomainException::class, 'Can only sail to an adjacent sector');
    expect($profile->position())->toBe($start)
        ->and($profile->tick())->toBe(0);
});

it('z technologią rejs o 2 kosztuje 1 tik i dopływa na miejsce', function (int $mx, int $my) {
    [$profile, $world, $start, $dx, $dy] = navigator([Technology::MapySztormowe, Technology::ZeglugaDwusektorowa]);
    $target = ['x' => $start['x'] + $mx * $dx, 'y' => $start['y'] + $my * $dy];

    $profile->sail($target['x'], $target['y'], $world);

    expect($profile->position())->toBe($target)
        ->and($profile->tick())->toBe(1)
        ->and($profile->moveCount())->toBe(1)
        ->and($profile->isVisited($target['x'], $target['y']))->toBeTrue();
})->with([
    'prosto' => [2, 0],
    'po skosie' => [2, 2],
    'ruch (2,1)' => [2, 1],
    'sąsiedni dalej możliwy' => [1, 0],
]);

it('sektor, przez który flota przepływa, jest odkryty i płaci kartografię, ale nie jest odwiedzony', function () {
    [$profile, $world, $start, $dx, $dy] = navigator([Technology::MapySztormowe, Technology::ZeglugaDwusektorowa]);
    $through = ['x' => $start['x'] + $dx, 'y' => $start['y'] + $dy];

    $outcome = $profile->sail($start['x'] + 2 * $dx, $start['y'] + 2 * $dy, $world);

    expect($outcome['discoveredNow'])->toContain("{$through['x']}:{$through['y']}")
        ->and($profile->isDiscovered($through['x'], $through['y']))->toBeTrue()
        ->and($profile->isVisited($through['x'], $through['y']))->toBeFalse();
});

it('rejs dalej niż zasięg jest odrzucany także z technologią', function () {
    [$profile, $world, $start, $dx] = navigator([Technology::MapySztormowe, Technology::ZeglugaDwusektorowa]);

    expect(fn () => $profile->sail($start['x'] + 3 * $dx, $start['y'], $world))
        ->toThrow(DomainException::class, 'Sector beyond sailing range');
    expect(fn () => $profile->sail($start['x'], $start['y'], $world))
        ->toThrow(DomainException::class, 'Can only sail to an adjacent sector');
    expect($profile->tick())->toBe(0);
});

it('sztorm i straty ładunku na celu rejsu o 2 są takie jak przy rejsie o 1', function () {
    // tik, na którym rejs trafia w fale nawet z Mapami sztormowymi (rzut < 10, efekt parzysty)
    $seed = 4242;
    $tick = 0;
    while (crc32("$seed:".($tick + 1)) % 100 >= 10 || 0 !== crc32("$seed:".($tick + 1).':effect') % 2) {
        ++$tick;
    }
    // konwój bez eskorty: fale zabierają dwa razy więcej
    $convoy = [ShipType::Transportowiec, ShipType::Tratwa];
    $cargo = new Resources(wood: 2, steel: 50, gold: 8);
    [$near, $world, $start, $dx] = navigator([Technology::MapySztormowe], $tick, $convoy, $cargo, $seed);
    [$far] = navigator([Technology::MapySztormowe, Technology::ZeglugaDwusektorowa], $tick, $convoy, $cargo, $seed);

    $one = $near->sail($start['x'] + $dx, $start['y'], $world)['event'];
    $two = $far->sail($start['x'] + 2 * $dx, $start['y'], $world)['event'];

    expect($two)->toBe(['type' => 'storm', 'effect' => 'cargo-lost', 'cargoLost' => ['wood' => 2, 'steel' => 10, 'gold' => 0], 'unescorted' => true])
        ->and($two)->toBe($one);
});

it('rejs o 2 na odwiedzony sektor jest bezpieczny', function () {
    $seed = 4242;
    $tick = 0;
    while (crc32("$seed:".($tick + 1)) % 100 >= 10) {
        ++$tick;
    }
    [$probe, $world, $start, $dx] = navigator([], seed: $seed);
    $target = ['x' => $start['x'] + 2 * $dx, 'y' => $start['y']];
    $profile = CaptainProfile::fromSnapshot(
        $probe->id(), 'Jacek', 1000, [], null, Resources::none(), $seed, $start, [],
        tick: $tick, visited: ["{$start['x']}:{$start['y']}", "{$target['x']}:{$target['y']}"],
        researched: [Technology::MapySztormowe, Technology::ZeglugaDwusektorowa],
    );

    expect($profile->sail($target['x'], $target['y'], $world)['event'])->toBeNull();
});
