<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\WorldMap;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

const CONVOY_ISLANDS = ['zatoka-rozbitka', 'mielizny', 'wyspa-sygnalowa', 'archipelag-mgiel', 'ciesnina-sztormow', 'twierdza-admiralicji'];

/** @param list<ShipType> $types */
function convoyFleet(array $types): array
{
    return array_map(static fn (ShipType $t) => OwnedShip::build($t), $types);
}

/**
 * Profil w porcie startowym z zadaną flotą i ładunkiem, ustawiony tak, że
 * najbliższy rejs na nieodwiedzony sektor trafi w sztorm „fale zabrały”
 * (rzut deterministyczny z seed+tik — szukamy tiku, nie losujemy).
 *
 * @param list<ShipType> $types
 *
 * @return array{0: CaptainProfile, 1: WorldMap, 2: array{x:int,y:int}}
 */
function profileBeforeWaves(array $types, Resources $cargo): array
{
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $tick = 0;
    // sail() podbija tik przed rzutem; parzysty rzut efektu = fale (nieparzysty uszkadza statek)
    while (crc32("$seed:".($tick + 1)) % 100 >= 20 || 0 !== crc32("$seed:".($tick + 1).':effect') % 2) {
        ++$tick;
    }

    $world = WorldMap::generate($seed, CONVOY_ISLANDS);
    $start = $world->startPosition();
    $profile = CaptainProfile::fromSnapshot($id, 'Jacek', 1000, [], convoyFleet($types), $cargo, $seed, $start, tick: $tick);
    $profile->ensureWorldState($world);

    $target = ['x' => $start['x'] + ($start['x'] < WorldMap::WIDTH - 1 ? 1 : -1), 'y' => $start['y']];

    return [$profile, $world, $target];
}

it('transportowiec: długi, pojemny, bezbronny, od Marynarza i stoczni 2', function () {
    $t = ShipType::Transportowiec;

    expect($t->length())->toBe(5)
        ->and($t->cargoCapacity())->toBe(60)
        ->and($t->buildCost())->toEqual(new Resources(wood: 40, steel: 10))
        ->and($t->repairCost())->toEqual(new Resources(wood: 15, steel: 5))
        ->and($t->requiredRank()->value)->toBe('marynarz')
        ->and($t->requiredShipyardLevel())->toBe(2)
        ->and($t->isArmed())->toBeFalse();
});

it('tratwa nie ma ładowni, a broń mają tylko nośniki', function () {
    $armed = array_values(array_filter(ShipType::cases(), static fn (ShipType $t) => $t->isArmed()));

    expect(ShipType::Tratwa->cargoCapacity())->toBe(0)
        ->and($armed)->toBe([ShipType::Kuter, ShipType::Niszczyciel, ShipType::Lotniskowiec]);
});

it('domyślny skład pomija transportowiec, nawet gdy zostaje miejsce', function () {
    $profile = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 1000, [],
        convoyFleet([ShipType::Transportowiec, ShipType::Kuter, ShipType::Tratwa]));

    $squad = $profile->battleSquad(null, 20);

    expect(array_map(static fn ($s) => $s->type, $squad))->toBe([ShipType::Kuter, ShipType::Tratwa]);
});

it('transportowiec płynie do bitwy wskazany wprost, ale tylko z eskortą', function () {
    $fleet = convoyFleet([ShipType::Transportowiec, ShipType::Kuter, ShipType::Tratwa]);
    $profile = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 1000, [], $fleet);
    [$transport, $kuter, $tratwa] = $fleet;

    expect($profile->battleSquad([$transport->id, $kuter->id], 9))->toHaveCount(2)
        ->and(fn () => $profile->battleSquad([$transport->id, $tratwa->id], 9))->toThrow(DomainException::class, 'Convoy needs an escort');
});

it('sam transportowiec nie popłynie do bitwy w doborze domyślnym', function () {
    $profile = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 1000, [],
        convoyFleet([ShipType::Transportowiec]));

    $profile->battleSquad(null, 9);
})->throws(DomainException::class, 'Convoy needs an escort');

it('skład z samych tratw nie jest konwojem — rozbitek nadal walczy', function () {
    $fleet = convoyFleet([ShipType::Tratwa, ShipType::Tratwa]);
    $profile = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 0, [], $fleet);

    expect($profile->battleSquad([$fleet[0]->id, $fleet[1]->id], 9))->toHaveCount(2)
        ->and(CaptainProfile::isUnescortedConvoy($fleet))->toBeFalse();
});

it('uszkodzona eskorta nie chroni konwoju', function () {
    [$transport, $kuter] = convoyFleet([ShipType::Transportowiec, ShipType::Kuter]);
    $kuter->markDamaged();
    $profile = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 1000, [], [$transport, $kuter]);

    expect(CaptainProfile::isUnescortedConvoy($profile->activeFleet()))->toBeTrue();
});

it('fale zabierają 10% ładunku: najpierw drewno, potem stal', function () {
    [$profile, $world, $target] = profileBeforeWaves(
        [ShipType::Transportowiec, ShipType::Kuter],
        new Resources(wood: 2, steel: 50, gold: 8),
    );

    $event = $profile->sail($target['x'], $target['y'], $world)['event'];

    expect($event)->toBe(['type' => 'storm', 'effect' => 'cargo-lost', 'cargoLost' => ['wood' => 2, 'steel' => 4, 'gold' => 0], 'unescorted' => false])
        ->and($profile->cargo()->steel)->toBe(46)
        ->and($profile->cargo()->gold)->toBe(8);
});

it('konwój bez eskorty traci w sztormie dwa razy więcej', function () {
    [$profile, $world, $target] = profileBeforeWaves(
        [ShipType::Transportowiec, ShipType::Tratwa],
        new Resources(wood: 2, steel: 50, gold: 8),
    );

    $event = $profile->sail($target['x'], $target['y'], $world)['event'];

    expect($event['cargoLost'])->toBe(['wood' => 2, 'steel' => 10, 'gold' => 0])
        ->and($event['unescorted'])->toBeTrue();
});

it('mały ładunek traci minimum drewna (5, bez eskorty 10), o ile jest', function (array $types, int $wood, int $lost) {
    [$profile, $world, $target] = profileBeforeWaves($types, new Resources(wood: $wood));

    $event = $profile->sail($target['x'], $target['y'], $world)['event'];

    expect($event['cargoLost']['wood'])->toBe($lost);
})->with([
    'kuter, 8 drewna' => [[ShipType::Kuter], 8, 5],
    'kuter, 3 drewna' => [[ShipType::Kuter], 3, 3],
    'konwój bez eskorty, 14 drewna' => [[ShipType::Transportowiec], 14, 10],
]);

it('ładunek ponad pojemność ze starego snapshotu nie przepada — trafia do magazynu wyspy', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    $world = WorldMap::generate($profile->worldSeed(), CONVOY_ISLANDS);
    $profile->ensureWorldState($world);
    $state = $m->toArray($profile);
    $state['cargo'] = ['wood' => 26, 'steel' => 4, 'gold' => 0]; // zapisane, gdy tratwy miały ładownię (15 + 3×5)

    $restored = $m->toDomain((string) $profile->id(), $state);
    // zapis przed rozłożeniem (np. rozliczenie bitwy) nie gubi nadwyżki
    $restored = $m->toDomain((string) $profile->id(), $m->toArray($restored));
    $restored->ensureWorldState($world);

    expect($restored->cargo()->toArray())->toBe(['wood' => 11, 'steel' => 4, 'gold' => 0])
        ->and($restored->stockpile('zatoka-rozbitka')->wood)->toBe(20 + 15)
        ->and($restored->unplacedResources())->toBeNull();
});
