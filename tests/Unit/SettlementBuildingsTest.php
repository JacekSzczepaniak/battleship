<?php

declare(strict_types=1);

use App\Domain\Expedition\BuildingBlock;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

function settlementIsland(string $id): Island
{
    return (new StaticIslandCatalog())->byId($id) ?? throw new LogicException("No island $id");
}

/**
 * Kapitan z flotą startową (kuter: ładownia 15) zakotwiczony przy wyspie.
 *
 * @param list<string>                      $owned
 * @param array<string, list<BuildingType>> $buildings
 * @param array<string, int>                $depositTicks
 *
 * @return array{0: CaptainProfile, 1: WorldMap}
 */
function settler(
    string $at = 'mielizny',
    array $owned = ['mielizny'],
    ?Resources $stock = null,
    ?Resources $cargo = null,
    array $buildings = [],
    int $tick = 0,
    array $depositTicks = [],
): array {
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $islands = (new StaticIslandCatalog())->all();
    $world = WorldMap::generate($seed, array_map(static fn ($i) => $i->id, $islands), $islands);

    $profile = CaptainProfile::fromSnapshot(
        $id, 'Jacek', 40, [], null, $cargo ?? Resources::none(), $seed, $world->islandPosition($at),
        tick: $tick,
        stockpiles: null !== $stock ? [$at => $stock] : [],
        depositTicks: $depositTicks + array_fill_keys($owned, $tick),
        ownedIslands: array_fill_keys($owned, 0),
        buildings: $buildings,
    );

    return [$profile, $world];
}

it('tartak na Mieliznach daje 6 drewna na tik i limit 60, bez tartaku 3 i 30', function () {
    $mielizny = settlementIsland('mielizny');
    [$bare] = settler();
    [$withSawmill] = settler(buildings: ['mielizny' => [BuildingType::Tartak]]);

    expect($bare->depositYield($mielizny)->toArray())->toBe(['wood' => 3, 'steel' => 0, 'gold' => 0])
        ->and($bare->depositCap($mielizny)->toArray())->toBe(['wood' => 30, 'steel' => 0, 'gold' => 0])
        ->and($withSawmill->depositYield($mielizny)->toArray())->toBe(['wood' => 6, 'steel' => 0, 'gold' => 0])
        ->and($withSawmill->depositCap($mielizny)->toArray())->toBe(['wood' => 60, 'steel' => 0, 'gold' => 0]);
});

it('złoże z tartakiem naliczone do magazynu: 6 na tik, najwyżej 60', function () {
    [$profile, $world] = settler(buildings: ['mielizny' => [BuildingType::Tartak]], tick: 4, depositTicks: ['mielizny' => 0]);
    $profile->ensureWorldState($world);
    expect($profile->stockpile('mielizny')->wood)->toBe(24);

    [$profile, $world] = settler(buildings: ['mielizny' => [BuildingType::Tartak]], tick: 30, depositTicks: ['mielizny' => 0]);
    $profile->ensureWorldState($world);
    expect($profile->stockpile('mielizny')->wood)->toBe(60);
});

it('kopalnia podwaja stal Archipelagu i złoto Cieśniny', function () {
    [$profile] = settler(at: 'archipelag-mgiel', owned: ['archipelag-mgiel', 'ciesnina-sztormow'], buildings: [
        'archipelag-mgiel' => [BuildingType::Kopalnia],
        'ciesnina-sztormow' => [BuildingType::Kopalnia],
    ]);

    expect($profile->depositYield(settlementIsland('archipelag-mgiel'))->toArray())->toBe(['wood' => 0, 'steel' => 4, 'gold' => 0])
        ->and($profile->depositCap(settlementIsland('archipelag-mgiel'))->toArray())->toBe(['wood' => 0, 'steel' => 40, 'gold' => 0])
        ->and($profile->depositYield(settlementIsland('ciesnina-sztormow'))->toArray())->toBe(['wood' => 0, 'steel' => 0, 'gold' => 2]);
});

it('budowa płaci najpierw z magazynu wyspy, resztę z ładowni, i nie kosztuje tiku', function () {
    [$profile, $world] = settler(stock: new Resources(wood: 25, steel: 10, gold: 3), cargo: new Resources(wood: 8));

    $profile->buildBuilding(BuildingType::Tartak, settlementIsland('mielizny'), $world);

    expect($profile->buildingsOn('mielizny'))->toBe([BuildingType::Tartak])
        ->and($profile->hasActiveBuilding('mielizny', BuildingType::Tartak))->toBeTrue()
        ->and($profile->stockpile('mielizny')->toArray())->toBe(['wood' => 0, 'steel' => 0, 'gold' => 3])
        ->and($profile->cargo()->toArray())->toBe(['wood' => 3, 'steel' => 0, 'gold' => 0])
        ->and($profile->tick())->toBe(0);
});

it('nowy tartak nie podwaja urobku za tiki sprzed budowy', function () {
    // 4 tiki bez naliczenia: magazyn 10 + 12 drewna ze zwykłego złoża (z tartakiem byłoby + 24),
    // więc z 30 drewna za tartak ładownia dopłaca 8
    [$profile, $world] = settler(stock: new Resources(wood: 10, steel: 10), cargo: new Resources(wood: 10), tick: 4, depositTicks: ['mielizny' => 0]);

    $profile->buildBuilding(BuildingType::Tartak, settlementIsland('mielizny'), $world);

    expect($profile->stockpile('mielizny')->wood)->toBe(0)
        ->and($profile->cargo()->wood)->toBe(2);
});

it('budowa jest odrzucana', function (array $settler, BuildingType $type, string $island, string $message) {
    [$profile, $world] = settler(...$settler);
    $stockBefore = $profile->stockpile($island);

    expect(fn () => $profile->buildBuilding($type, settlementIsland($island), $world))
        ->toThrow(DomainException::class, $message);
    expect($profile->stockpile($island))->toEqual($stockBefore);
})->with([
    'wyspa bez bandery' => [['owned' => [], 'stock' => new Resources(100, 100, 100)], BuildingType::Tartak, 'mielizny', 'Island not owned'],
    'kapitan nie stoi na wyspie' => [['at' => 'zatoka-rozbitka', 'stock' => new Resources(100, 100, 100)], BuildingType::Tartak, 'mielizny', 'Not at island'],
    'kopalnia bez złoża stali i złota' => [['stock' => new Resources(100, 100, 100)], BuildingType::Kopalnia, 'mielizny', 'Building site not suitable'],
    'tartak bez złoża drewna' => [['at' => 'zatoka-rozbitka', 'owned' => ['zatoka-rozbitka'], 'stock' => new Resources(100, 100, 100)], BuildingType::Tartak, 'zatoka-rozbitka', 'Building site not suitable'],
    'drugi tartak na wyspie' => [['stock' => new Resources(100, 100, 100), 'buildings' => ['mielizny' => [BuildingType::Tartak]]], BuildingType::Tartak, 'mielizny', 'Building already standing'],
    'brak surowców' => [['stock' => new Resources(wood: 30, steel: 9)], BuildingType::Tartak, 'mielizny', 'Not enough resources'],
]);

it('powód blokady wskazuje pierwszą niespełnioną regułę', function () {
    $mielizny = settlementIsland('mielizny');
    [$stranger, $world] = settler(owned: [], stock: new Resources(100, 100, 100));
    [$owner, $ownerWorld] = settler(stock: new Resources(100, 100, 100));

    expect($stranger->buildingBlocker(BuildingType::Kopalnia, $mielizny, $world))->toBe(BuildingBlock::NotOwned)
        ->and($owner->buildingBlocker(BuildingType::Kopalnia, $mielizny, $ownerWorld))->toBe(BuildingBlock::Site)
        ->and($owner->buildingBlocker(BuildingType::Tartak, $mielizny, $ownerWorld))->toBeNull();
});

it('po utracie wyspy w kontrataku tartak stoi, ale nie produkuje; po odbiciu produkuje znowu', function () {
    // kontratak (#78) uderza w Mielizny na tiku 2, kapitan nie rozpoczął obrony
    [$owner, $world] = settler(buildings: ['mielizny' => [BuildingType::Tartak]]);
    $profile = CaptainProfile::fromSnapshot(
        $owner->id(), 'Jacek', 40, [], null, Resources::none(), $owner->worldSeed(), $owner->position(),
        tick: 5, depositTicks: ['mielizny' => 0], ownedIslands: ['mielizny' => 0],
        counterattacks: ['pending' => ['island' => 'mielizny', 'announcedAt' => 0, 'strikeAt' => 2, 'defense' => null], 'calmFrom' => 0, 'rolledTo' => 0],
        buildings: $owner->buildings(),
    );
    $profile->ensureWorldState($world);

    // urobek z tartakiem do tiku uderzenia (2 × 6 = 12), połowa przepada, potem złoże stoi
    expect($profile->ownsIsland('mielizny'))->toBeFalse()
        ->and($profile->buildingsOn('mielizny'))->toBe([BuildingType::Tartak])
        ->and($profile->hasActiveBuilding('mielizny', BuildingType::Tartak))->toBeFalse()
        ->and($profile->stockpile('mielizny')->wood)->toBe(6);

    $recapture = GameId::new();
    $profile->startBattle('mielizny', $recapture);
    expect($profile->settleBattle($recapture, 'won', 50)['captured'])->toBeTrue();

    // kolejna bitwa (o inną wyspę) to jeden tik pracy złoża
    $next = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $next);
    $profile->settleBattle($next, 'lost', 0);
    $profile->ensureWorldState($world);

    expect($profile->hasActiveBuilding('mielizny', BuildingType::Tartak))->toBeTrue()
        ->and($profile->stockpile('mielizny')->wood)->toBe(12);
});

it('budynki przeżywają zapis i odczyt profilu', function () {
    $m = new ProfileSnapshotMapper();
    [$profile] = settler(buildings: ['mielizny' => [BuildingType::Tartak], 'archipelag-mgiel' => [BuildingType::Kopalnia]]);

    $state = $m->toArray($profile);
    $restored = $m->toDomain((string) $profile->id(), $state);

    expect($state['buildings'])->toBe(['mielizny' => ['tartak'], 'archipelag-mgiel' => ['kopalnia']])
        ->and($restored->buildings())->toBe($profile->buildings());
});

it('snapshot bez budynków, z nieznanym albo powtórzonym typem', function () {
    $m = new ProfileSnapshotMapper();
    [$profile] = settler();
    $state = $m->toArray($profile);

    unset($state['buildings']);
    expect($m->toDomain((string) $profile->id(), $state)->buildings())->toBe([]);

    $state['buildings'] = ['mielizny' => ['tartak', 'tartak', 'uczelnia'], 'zatoka-rozbitka' => ['uczelnia']];
    expect($m->toDomain((string) $profile->id(), $state)->buildings())->toBe(['mielizny' => [BuildingType::Tartak]]);
});
