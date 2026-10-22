<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

/** Mapa świata z wyspami i złożami z katalogu — jak WorldFactory. */
function depositWorld(CaptainProfile $profile): WorldMap
{
    $islands = (new StaticIslandCatalog())->all();

    return WorldMap::generate($profile->worldSeed(), array_map(static fn ($i) => $i->id, $islands), $islands);
}

/**
 * Kapitan na tiku $tick, z wygraną bitwą o Mielizny (złoże: 3 drewna/tik).
 *
 * @param array<string,int>        $depositTicks
 * @param array<string, Resources> $stockpiles
 */
function mieliznyOwner(int $tick, array $depositTicks, array $stockpiles = []): CaptainProfile
{
    $battles = [(string) GameId::new() => ['island' => 'mielizny', 'settled' => true, 'result' => 'won']];

    return CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 40, $battles, null, Resources::none(),
        tick: $tick, stockpiles: $stockpiles, depositTicks: $depositTicks,
    );
}

it('stocznie stoją tylko na dwóch wyspach: Zatoce (1) i Sygnałowej (3)', function () {
    $levels = [];
    foreach ((new StaticIslandCatalog())->all() as $island) {
        if ($island->shipyardLevel > 0) {
            $levels[$island->id] = $island->shipyardLevel;
        }
    }

    expect($levels)->toBe(['zatoka-rozbitka' => 1, 'wyspa-sygnalowa' => 3]);
});

it('złoża są na wyspach bez stoczni', function () {
    $deposits = [];
    foreach ((new StaticIslandCatalog())->all() as $island) {
        if ($island->hasDeposit()) {
            $deposits[$island->id] = $island->depositYield->toArray();
            expect($island->shipyardLevel)->toBe(0);
        }
    }

    expect($deposits)->toBe([
        'mielizny' => ['wood' => 3, 'steel' => 0, 'gold' => 0],
        'archipelag-mgiel' => ['wood' => 0, 'steel' => 2, 'gold' => 0],
        'ciesnina-sztormow' => ['wood' => 0, 'steel' => 0, 'gold' => 1],
    ]);
});

it('tratwę zbuduje się bez stoczni, kuter już nie', function () {
    expect(ShipType::Tratwa->requiredShipyardLevel())->toBe(0)
        ->and(ShipType::Kuter->requiredShipyardLevel())->toBe(1);
});

it('złoże zdobytej wyspy produkuje co tik do magazynu wyspy', function () {
    $profile = mieliznyOwner(tick: 7, depositTicks: ['mielizny' => 3]);

    $profile->ensureWorldState(depositWorld($profile));

    expect($profile->stockpile('mielizny')->toArray())->toBe(['wood' => 12, 'steel' => 0, 'gold' => 0])
        ->and($profile->depositTicks())->toBe(['mielizny' => 7]);
});

it('naliczenie jest idempotentne w obrębie tiku', function () {
    $profile = mieliznyOwner(tick: 7, depositTicks: ['mielizny' => 3]);
    $world = depositWorld($profile);

    $profile->ensureWorldState($world);
    $profile->ensureWorldState($world);

    expect($profile->stockpile('mielizny')->wood)->toBe(12);
});

it('złoże staje, gdy urobek w magazynie sięga 10 tików produkcji', function (int $stock, int $expected) {
    $profile = mieliznyOwner(tick: 100, depositTicks: ['mielizny' => 0], stockpiles: ['mielizny' => new Resources(wood: $stock, steel: 4)]);

    $profile->ensureWorldState(depositWorld($profile));

    expect($profile->stockpile('mielizny')->toArray())->toBe(['wood' => $expected, 'steel' => 4, 'gold' => 0]);
})->with([
    'pusty magazyn' => [0, 30],
    'częściowo pełny' => [25, 30],
    'ponad limit (łup)' => [40, 40],
]);

it('wyspa bez wygranej bitwy nie produkuje', function () {
    $profile = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 40, [], null, Resources::none(), tick: 50);

    $profile->ensureWorldState(depositWorld($profile));

    expect($profile->stockpile('mielizny')->isZero())->toBeTrue()
        ->and($profile->depositTicks())->toBe([]);
});

it('wyspa wygrana przed złożami produkuje od pierwszego naliczenia, bez wyrównania wstecz', function () {
    $m = new ProfileSnapshotMapper();
    $profile = mieliznyOwner(tick: 50, depositTicks: []);
    $profile->ensureWorldState(depositWorld($profile));

    expect($profile->stockpile('mielizny')->isZero())->toBeTrue()
        ->and($profile->depositTicks())->toBe(['mielizny' => 50]);

    $state = $m->toArray($profile);
    $state['tick'] = 52;
    $restored = $m->toDomain((string) $profile->id(), $state);
    $restored->ensureWorldState(depositWorld($restored));

    expect($restored->stockpile('mielizny')->wood)->toBe(6);
});

it('pierwsza wygrana uruchamia złoże od tiku rozliczenia; porażka nie', function () {
    $profile = CaptainProfile::create('Jacek');
    $lost = GameId::new();
    $profile->startBattle('mielizny', $lost);
    $profile->settleBattle($lost, 'lost', 12, new Resources(wood: 6));

    expect($profile->depositTicks())->toBe([]);

    $won = GameId::new();
    $profile->startBattle('mielizny', $won);
    $profile->settleBattle($won, 'won', 50, new Resources(wood: 18));

    expect($profile->depositTicks())->toBe(['mielizny' => $profile->tick()]);
});
