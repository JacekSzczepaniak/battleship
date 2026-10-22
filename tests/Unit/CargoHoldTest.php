<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

const CARGO_ISLANDS = ['zatoka-rozbitka', 'mielizny', 'wyspa-sygnalowa', 'archipelag-mgiel', 'ciesnina-sztormow', 'twierdza-admiralicji'];

/** Profil stojący w porcie Zatoki Rozbitka. */
function dockedProfile(): array
{
    $profile = CaptainProfile::create('Jacek');
    $world = WorldMap::generate($profile->worldSeed(), CARGO_ISLANDS);
    $profile->ensureWorldState($world);

    return [$profile, $world];
}

/** Sąsiedni sektor startu bez wyspy (pełne morze). */
function openSeaNeighbour(WorldMap $world): array
{
    $start = $world->startPosition();
    for ($dx = -1; $dx <= 1; ++$dx) {
        for ($dy = -1; $dy <= 1; ++$dy) {
            $x = $start['x'] + $dx;
            $y = $start['y'] + $dy;
            if (($dx || $dy) && $world->isInside($x, $y) && null === $world->islandAt($x, $y)) {
                return ['x' => $x, 'y' => $y];
            }
        }
    }
    throw new LogicException('No open sea next to start');
}

it('pojemność ładowni to suma sprawnych statków: flota startowa unosi 30', function () {
    $profile = CaptainProfile::create('Jacek');

    expect($profile->cargoCapacity())->toBe(15 + 3 * 5)
        ->and($profile->cargo()->toArray())->toBe(['wood' => 20, 'steel' => 0, 'gold' => 0]);
});

it('rozładunek i załadunek przenoszą surowce między ładownią a magazynem za tik', function () {
    [$profile] = dockedProfile();
    $tick = $profile->tick();

    $profile->unloadCargo('zatoka-rozbitka', new Resources(wood: 12));
    expect($profile->cargo()->wood)->toBe(8)
        ->and($profile->stockpile('zatoka-rozbitka')->wood)->toBe(12)
        ->and($profile->tick())->toBe($tick + 1);

    $profile->loadCargo('zatoka-rozbitka', new Resources(wood: 5));
    expect($profile->cargo()->wood)->toBe(13)
        ->and($profile->stockpile('zatoka-rozbitka')->wood)->toBe(7)
        ->and($profile->tick())->toBe($tick + 2);
});

it('załadunek odrzuca przepełnienie, brak w magazynie i pustą operację', function () {
    [$profile] = dockedProfile();
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 15, steel: 5));

    // ładownia: 20/30, magazyn: 15 drewna + 5 stali
    expect(fn () => $profile->loadCargo('zatoka-rozbitka', new Resources(wood: 15)))->toThrow(DomainException::class, 'Cargo capacity exceeded')
        ->and(fn () => $profile->loadCargo('zatoka-rozbitka', new Resources(steel: 6)))->toThrow(DomainException::class, 'Not enough resources')
        ->and(fn () => $profile->loadCargo('zatoka-rozbitka', Resources::none()))->toThrow(InvalidArgumentException::class, 'Nothing to transfer');

    $profile->loadCargo('zatoka-rozbitka', new Resources(wood: 5, steel: 5));
    expect($profile->cargo()->toArray())->toBe(['wood' => 25, 'steel' => 5, 'gold' => 0]);
});

it('budowa płaci najpierw z magazynu wyspy, resztę z ładowni', function () {
    [$profile] = dockedProfile();
    $profile->unloadCargo('zatoka-rozbitka', new Resources(wood: 12)); // magazyn 12, ładownia 8

    $profile->buildShip(ShipType::Kuter, 'zatoka-rozbitka'); // 20 drewna

    expect($profile->stockpile('zatoka-rozbitka')->wood)->toBe(0)
        ->and($profile->cargo()->wood)->toBe(0);
});

it('kartografia płaci do ładowni, nadmiar ponad pojemność przepada', function () {
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 0, [], null, new Resources(wood: 29),
    );
    $world = WorldMap::generate($profile->worldSeed(), CARGO_ISLANDS);
    $profile->ensureWorldState($world);
    $target = openSeaNeighbour($world);

    $outcome = $profile->sail($target['x'], $target['y'], $world);

    $stormWood = 'wood-lost' === ($outcome['event']['effect'] ?? null) ? $outcome['event']['wood'] : 0;
    expect($profile->cargo()->total())->toBeLessThanOrEqual($profile->cargoCapacity())
        ->and($outcome['cartography'] - $outcome['cartographyLost'])->toBe($profile->cargo()->wood - 29 + $stormWood + ($outcome['event']['cargoLost']['wood'] ?? 0));
    if ($outcome['cartography'] > 1 && null === $outcome['event']) {
        expect($outcome['cartographyLost'])->toBe($outcome['cartography'] - 1); // zmieściło się 1 drewno
    }
});

it('przegrana bez floty: ładunek przepada razem ze statkami', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId, array_map(static fn ($s) => $s->id, $profile->activeFleet()));

    $outcome = $profile->settleBattle($gameId, 'lost', 10, new Resources(wood: 5), [2, 1, 1, 1]);

    expect($outcome['cargoLost']->wood)->toBe(20)
        ->and($profile->cargo()->isZero())->toBeTrue()
        ->and($profile->stockpile('zatoka-rozbitka')->wood)->toBe(5); // łup za porażkę czeka w porcie
});

it('stary magazyn profilu trafia do magazynu wyspy, na której stoi kapitan', function () {
    $m = new ProfileSnapshotMapper();
    [$docked, $world] = dockedProfile();
    $state = $m->toArray($docked);
    unset($state['cargo'], $state['stockpiles']);
    $state['materials'] = 37; // profil sprzed trzech surowców

    $restored = $m->toDomain((string) $docked->id(), $state);
    $restored->ensureWorldState($world);

    expect($restored->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 37, 'steel' => 0, 'gold' => 0])
        ->and($restored->cargo()->isZero())->toBeTrue();
});

it('na morzu stary magazyn idzie do ładowni, nadwyżka do magazynu wyspy startowej', function () {
    $m = new ProfileSnapshotMapper();
    [$profile, $world] = dockedProfile();
    $sea = openSeaNeighbour($world);
    $profile->sail($sea['x'], $sea['y'], $world);
    $state = $m->toArray($profile);
    unset($state['cargo'], $state['stockpiles']);
    $state['resources'] = ['wood' => 40, 'steel' => 6, 'gold' => 2]; // snapshot z #45

    $restored = $m->toDomain((string) $profile->id(), $state);
    $restored->ensureWorldState($world);
    $capacity = $restored->cargoCapacity();

    // najcenniejsze zostają w ładowni: złoto, stal, potem drewno do pełna
    expect($restored->cargo()->toArray())->toBe(['wood' => $capacity - 8, 'steel' => 6, 'gold' => 2])
        ->and($restored->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 48 - $capacity, 'steel' => 0, 'gold' => 0]);
});

it('ładownia i magazyny round-tripują przez snapshot', function () {
    $m = new ProfileSnapshotMapper();
    [$profile] = dockedProfile();
    $profile->unloadCargo('zatoka-rozbitka', new Resources(wood: 7));

    $restored = $m->toDomain((string) $profile->id(), $m->toArray($profile));

    expect($restored->cargo())->toEqual($profile->cargo())
        ->and($restored->stockpile('zatoka-rozbitka'))->toEqual(new Resources(wood: 7));
});
