<?php

declare(strict_types=1);

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\TechBranch;
use App\Domain\Expedition\TechLevel;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;

// Technologie ekonomiczne (#96): Pojemne ładownie, Dok remontowy, Lepsze narzędzia.

function economyIsland(string $id): Island
{
    return (new StaticIslandCatalog())->byId($id) ?? throw new LogicException("No island $id");
}

/**
 * Kapitan zakotwiczony przy wyspie, z wyspami pod banderą od tiku 0.
 *
 * @param list<OwnedShip>|null                                                              $fleet
 * @param list<string>                                                                      $owned
 * @param array<string, list<BuildingType>>                                                 $buildings
 * @param list<Technology>                                                                  $researched
 * @param array{technology: Technology, island: string, progress: int, countedTo: int}|null $research
 *
 * @return array{0: CaptainProfile, 1: WorldMap}
 */
function economist(
    ?array $fleet = null,
    ?Resources $cargo = null,
    string $at = 'zatoka-rozbitka',
    array $owned = ['zatoka-rozbitka'],
    array $buildings = [],
    array $researched = [],
    ?array $research = null,
    int $tick = 0,
    array $stockpiles = [],
): array {
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $islands = (new StaticIslandCatalog())->all();
    $world = WorldMap::generate($seed, array_map(static fn ($i) => $i->id, $islands), $islands);

    $profile = CaptainProfile::fromSnapshot(
        $id, 'Jacek', 300, [], $fleet, $cargo ?? Resources::none(), $seed, $world->islandPosition($at),
        tick: $tick,
        stockpiles: $stockpiles,
        depositTicks: array_fill_keys($owned, 0),
        ownedIslands: array_fill_keys($owned, 0),
        buildings: $buildings,
        researched: $researched,
        research: $research,
    );

    return [$profile, $world];
}

/** @return list<OwnedShip> */
function shipsOf(ShipType ...$types): array
{
    return array_map(static fn (ShipType $t) => OwnedShip::build($t), $types);
}

it('trzy technologie ekonomiczne siedzą w Kadłubach i Osadach, Lepsze narzędzia za Spichlerzami', function () {
    expect(Technology::PojemneLadownie->branch())->toBe(TechBranch::Kadluby)
        ->and(Technology::PojemneLadownie->level())->toBe(TechLevel::First)
        ->and(Technology::DokRemontowy->branch())->toBe(TechBranch::Kadluby)
        ->and(Technology::DokRemontowy->level())->toBe(TechLevel::Second)
        ->and(Technology::LepszeNarzedzia->branch())->toBe(TechBranch::Osady)
        ->and(Technology::LepszeNarzedzia->level())->toBe(TechLevel::Second)
        ->and(Technology::Spichlerze->branch())->toBe(TechBranch::Osady)
        ->and(Technology::Spichlerze->level())->toBe(TechLevel::First);
});

it('Pojemne ładownie dają każdemu statkowi +50% ładowni w dół, tratwa zostaje przy 0', function () {
    [$base] = economist();
    [$profile] = economist(researched: [Technology::PojemneLadownie]);

    $capacities = static fn (CaptainProfile $p) => array_map(static fn (ShipType $t) => $p->shipCargoCapacity($t), ShipType::cases());

    expect($capacities($base))->toBe([0, 15, 20, 30, 60])
        ->and($capacities($profile))->toBe([0, 22, 30, 45, 90]);
});

it('ukończone Pojemne ładownie powiększają ładownię floty, ładunek zostaje i mieści się więcej', function () {
    [$profile, $world] = economist(
        fleet: shipsOf(ShipType::Kuter, ShipType::Kuter, ShipType::Tratwa),
        cargo: new Resources(wood: 20, steel: 6, gold: 4),
        buildings: ['zatoka-rozbitka' => [BuildingType::Uczelnia]],
        research: ['technology' => Technology::PojemneLadownie, 'island' => 'zatoka-rozbitka', 'progress' => 0, 'countedTo' => 0],
        tick: 6,
        stockpiles: ['zatoka-rozbitka' => new Resources(wood: 50)],
    );
    expect($profile->cargoCapacity())->toBe(30);

    $profile->ensureWorldState($world);

    expect($profile->hasResearched(Technology::PojemneLadownie))->toBeTrue()
        ->and($profile->cargoCapacity())->toBe(44)
        ->and($profile->cargo()->toArray())->toBe(['wood' => 20, 'steel' => 6, 'gold' => 4]);

    // 30 zajęte, wolne 14 z 44
    $profile->loadCargo('zatoka-rozbitka', new Resources(wood: 14));
    expect($profile->cargo()->total())->toBe(44)
        ->and(fn () => $profile->loadCargo('zatoka-rozbitka', new Resources(wood: 1)))->toThrow(DomainException::class, 'Cargo capacity exceeded');
});

it('ładunek ponad bazową pojemność z zapisu profilu z Pojemnymi ładowniami zostaje w ładowni', function () {
    [$profile] = economist(fleet: shipsOf(ShipType::Kuter), cargo: new Resources(wood: 22), researched: [Technology::PojemneLadownie]);

    expect($profile->cargo()->toArray())->toBe(['wood' => 22, 'steel' => 0, 'gold' => 0])
        ->and($profile->unplacedResources())->toBeNull();
});

it('Dok remontowy obniża remont do połowy w górę, tratwa dalej nic nie kosztuje', function () {
    [$base] = economist();
    [$profile] = economist(researched: [Technology::PojemneLadownie, Technology::DokRemontowy]);

    $costs = static fn (CaptainProfile $p) => array_map(static fn (ShipType $t) => $p->repairCost($t)->toArray(), ShipType::cases());

    expect($costs($base))->toBe(array_map(static fn (ShipType $t) => $t->repairCost()->toArray(), ShipType::cases()))
        ->and($costs($profile))->toBe([
            ['wood' => 0, 'steel' => 0, 'gold' => 0],
            ['wood' => 4, 'steel' => 0, 'gold' => 0],
            ['wood' => 5, 'steel' => 5, 'gold' => 0],
            ['wood' => 10, 'steel' => 13, 'gold' => 0],
            ['wood' => 8, 'steel' => 3, 'gold' => 0],
        ]);
});

it('remont z Dokiem remontowym płaci połowę ceny, a tratwy za darmo', function () {
    [$kuter, $niszczyciel, $tratwa] = $fleet = shipsOf(ShipType::Kuter, ShipType::Niszczyciel, ShipType::Tratwa);
    foreach ($fleet as $ship) {
        $ship->markDamaged();
    }
    [$profile] = economist(
        fleet: $fleet,
        cargo: new Resources(wood: 2),
        researched: [Technology::PojemneLadownie, Technology::DokRemontowy],
        stockpiles: ['zatoka-rozbitka' => new Resources(wood: 7, steel: 5)],
    );

    $profile->repairShip($kuter->id, 'zatoka-rozbitka');
    $profile->repairShip($tratwa->id, 'zatoka-rozbitka');

    // kuter: 4 drewna z magazynu; tratwa: nic
    expect($profile->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 3, 'steel' => 5, 'gold' => 0])
        ->and($profile->cargo()->toArray())->toBe(['wood' => 2, 'steel' => 0, 'gold' => 0]);

    // niszczyciel: 5 drewna (3 z magazynu, 2 z ładowni) i 5 stali
    $profile->repairShip($niszczyciel->id, 'zatoka-rozbitka');

    expect($profile->stockpile('zatoka-rozbitka')->isZero())->toBeTrue()
        ->and($profile->cargo()->isZero())->toBeTrue()
        ->and(array_map(static fn (OwnedShip $s) => $s->isDamaged(), $profile->fleet()))->toBe([false, false, false]);
});

it('Lepsze narzędzia dają złożu +1 na tik: Mielizny 4 drewna, Archipelag 3 stali, Cieśnina 2 złota', function () {
    [$base] = economist();
    [$profile] = economist(researched: [Technology::Spichlerze, Technology::LepszeNarzedzia]);
    $yield = static fn (CaptainProfile $p, string $id) => $p->depositYield(economyIsland($id))->toArray();

    expect($yield($base, 'mielizny'))->toBe(['wood' => 3, 'steel' => 0, 'gold' => 0])
        ->and($yield($profile, 'mielizny'))->toBe(['wood' => 4, 'steel' => 0, 'gold' => 0])
        ->and($yield($profile, 'archipelag-mgiel'))->toBe(['wood' => 0, 'steel' => 3, 'gold' => 0])
        ->and($yield($profile, 'ciesnina-sztormow'))->toBe(['wood' => 0, 'steel' => 0, 'gold' => 2])
        // wyspa bez złoża dalej nic nie daje
        ->and($profile->depositYield(economyIsland('zatoka-rozbitka'))->isZero())->toBeTrue();
});

it('tartak i kopalnia podwajają urobek już powiększony przez Lepsze narzędzia', function () {
    [$profile] = economist(
        buildings: ['mielizny' => [BuildingType::Tartak], 'archipelag-mgiel' => [BuildingType::Kopalnia], 'ciesnina-sztormow' => [BuildingType::Kopalnia]],
        researched: [Technology::Spichlerze, Technology::LepszeNarzedzia],
    );
    $yield = static fn (string $id) => $profile->depositYield(economyIsland($id))->toArray();

    expect($yield('mielizny'))->toBe(['wood' => 8, 'steel' => 0, 'gold' => 0])
        ->and($yield('archipelag-mgiel'))->toBe(['wood' => 0, 'steel' => 6, 'gold' => 0])
        ->and($yield('ciesnina-sztormow'))->toBe(['wood' => 0, 'steel' => 0, 'gold' => 4]);
});

it('złoże pod banderą nalicza powiększony urobek po ukończeniu Lepszych narzędzi, wcześniej po staremu', function () {
    // Lepsze narzędzia kończą się na tiku 10: do tiku 10 Mielizny dają 3 × 2 (tartak), potem 4 × 2
    [$profile, $world] = economist(
        at: 'mielizny',
        owned: ['mielizny'],
        buildings: ['mielizny' => [BuildingType::Tartak, BuildingType::Uczelnia]],
        researched: [Technology::Spichlerze],
        research: ['technology' => Technology::LepszeNarzedzia, 'island' => 'mielizny', 'progress' => 0, 'countedTo' => 0],
        tick: 12,
    );

    $profile->ensureWorldState($world);

    expect($profile->hasResearched(Technology::LepszeNarzedzia))->toBeTrue()
        ->and($profile->stockpile('mielizny')->toArray())->toBe(['wood' => 10 * 6 + 2 * 8, 'steel' => 0, 'gold' => 0])
        // limit magazynu rośnie z urobkiem: 8 × 20 tików (Spichlerze)
        ->and($profile->depositCap(economyIsland('mielizny'))->toArray())->toBe(['wood' => 160, 'steel' => 0, 'gold' => 0]);
});
