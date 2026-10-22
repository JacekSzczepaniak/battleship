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
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

const FORT_SEED = 7;

function fortSea(): WorldMap
{
    $islands = (new StaticIslandCatalog())->all();

    return WorldMap::generate(FORT_SEED, array_map(static fn ($i) => $i->id, $islands), $islands);
}

function fortIsland(string $id): Island
{
    return (new StaticIslandCatalog())->byId($id) ?? throw new LogicException("No island $id");
}

/** @return array{x:int,y:int} sąsiad Wyspy Sygnałowej w granicach mapy */
function fortShuttle(WorldMap $world): array
{
    $home = $world->islandPosition('wyspa-sygnalowa');

    return $world->isInside($home['x'] + 1, $home['y']) ? ['x' => $home['x'] + 1, 'y' => $home['y']] : ['x' => $home['x'] - 1, 'y' => $home['y']];
}

/**
 * Kapitan (marynarz) zakotwiczony przy wyspie, z odwiedzonym sąsiadem Wyspy
 * Sygnałowej — rejsy tam i z powrotem odmierzają tiki bez sztormów. Kontratak
 * zadany wprost: rzuty rozegrane do tiku przed bieżącym.
 *
 * @param list<string>                                                              $owned
 * @param array<string, Resources>                                                  $stockpiles
 * @param array<string, list<BuildingType>>                                         $buildings
 * @param array{island:string, announcedAt:int, strikeAt:int, defense:?string}|null $pending
 * @param array<string, int>                                                        $depositTicks
 */
function garrisonCaptain(
    array $owned = ['wyspa-sygnalowa'],
    array $stockpiles = [],
    array $buildings = [],
    int $tick = 0,
    ?array $pending = null,
    string $at = 'wyspa-sygnalowa',
    array $depositTicks = [],
): CaptainProfile {
    $world = fortSea();
    $pos = $world->islandPosition($at);
    $home = $world->islandPosition('wyspa-sygnalowa');
    $away = fortShuttle($world);

    return CaptainProfile::fromSnapshot(
        ProfileId::new(), 'Jacek', 80, [], null, Resources::none(), FORT_SEED, $pos,
        ["{$pos['x']}:{$pos['y']}"], 0, $tick, ["{$pos['x']}:{$pos['y']}", "{$home['x']}:{$home['y']}", "{$away['x']}:{$away['y']}"],
        $stockpiles, null, $depositTicks + array_fill_keys($owned, $tick), array_fill_keys($owned, 0),
        ['pending' => $pending, 'calmFrom' => 0, 'rolledTo' => max(0, $tick - 1), 'last' => null],
        $buildings,
    );
}

/** Jeden tik: rejs między Wyspą Sygnałową a sąsiednim sektorem, potem naliczenie świata. */
function garrisonTick(CaptainProfile $profile): void
{
    $world = fortSea();
    $home = $world->islandPosition('wyspa-sygnalowa');
    $target = $profile->position() === $home ? fortShuttle($world) : $home;
    $profile->sail($target['x'], $target['y'], $world);
    $profile->ensureWorldState($world);
}

/** Kontratak uderzył w tiku 30 (zapowiedź w 18), obrony nikt nie rozpoczął; jest tik 32. */
function struckAtThirty(string $island = 'wyspa-sygnalowa'): array
{
    return ['island' => $island, 'announcedAt' => 18, 'strikeAt' => 30, 'defense' => null];
}

it('fort kosztuje 40/40/10, staje na posiadanej wyspie i nie zmienia złoża ani stoczni', function () {
    $sygnalowa = fortIsland('wyspa-sygnalowa');
    $profile = garrisonCaptain(stockpiles: ['wyspa-sygnalowa' => new Resources(45, 40, 12)]);
    $world = fortSea();

    expect($profile->buildingBlocker(BuildingType::Fort, $sygnalowa, $world))->toBeNull();
    $profile->buildBuilding(BuildingType::Fort, $sygnalowa, $world);

    expect($profile->buildingsOn('wyspa-sygnalowa'))->toBe([BuildingType::Fort])
        ->and($profile->hasActiveBuilding('wyspa-sygnalowa', BuildingType::Fort))->toBeTrue()
        ->and($profile->stockpile('wyspa-sygnalowa')->toArray())->toBe(['wood' => 5, 'steel' => 0, 'gold' => 2])
        ->and($profile->shipyardLevel($sygnalowa))->toBe($sygnalowa->shipyardLevel)
        ->and($profile->depositYield(fortIsland('mielizny')))->toEqual(fortIsland('mielizny')->depositYield)
        ->and($profile->tick())->toBe(0);
});

it('fortu nie da się postawić w Zatoce Rozbitka', function () {
    $zatoka = fortIsland('zatoka-rozbitka');
    $profile = garrisonCaptain(owned: ['zatoka-rozbitka'], stockpiles: ['zatoka-rozbitka' => new Resources(100, 100, 100)], at: 'zatoka-rozbitka');

    expect(BuildingType::Fort->fitsSite($zatoka))->toBeFalse()
        ->and($profile->buildingBlocker(BuildingType::Fort, $zatoka, fortSea()))->toBe(BuildingBlock::Site);

    $profile->buildBuilding(BuildingType::Fort, $zatoka, fortSea());
})->throws(DomainException::class, 'Building site not suitable');

it('fort pasuje na każdą wyspę poza bezpieczną przystanią', function () {
    foreach ((new StaticIslandCatalog())->all() as $island) {
        expect(BuildingType::Fort->fitsSite($island))->toBe(!$island->safeHarbor);
    }
});

it('nieobecność przy kontrataku na wyspę z fortem: grabież połowy magazynu, bandera i budynki zostają, flota cała', function () {
    $profile = garrisonCaptain(
        stockpiles: ['wyspa-sygnalowa' => new Resources(21, 7, 3)],
        buildings: ['wyspa-sygnalowa' => [BuildingType::Fort]],
        tick: 32,
        pending: struckAtThirty(),
    );
    $fleet = array_map(static fn ($s) => [$s->id, $s->isDamaged()], $profile->fleet());

    $profile->ensureWorldState(fortSea());

    expect($profile->ownsIsland('wyspa-sygnalowa'))->toBeTrue()
        ->and($profile->buildingsOn('wyspa-sygnalowa'))->toBe([BuildingType::Fort])
        ->and($profile->stockpile('wyspa-sygnalowa'))->toEqual(new Resources(11, 4, 2))
        ->and($profile->counterattack())->toBeNull()
        ->and($profile->lastCounterattack())->toBe([
            'island' => 'wyspa-sygnalowa', 'tick' => 30, 'held' => false, 'absent' => true, 'plundered' => true,
            'stockpileLost' => ['wood' => 10, 'steel' => 3, 'gold' => 1],
        ])
        ->and($profile->counterattackState()['calmFrom'])->toBe(30)
        ->and(array_map(static fn ($s) => [$s->id, $s->isDamaged()], $profile->fleet()))->toBe($fleet);
});

it('złoże wyspy z fortem pracuje dalej po grabieży', function () {
    // złoże Mielizn (3 drewna/tik, limit 30) od tiku 20: w tiku uderzenia pełny magazyn, połowa przepada
    $profile = garrisonCaptain(
        owned: ['mielizny'],
        buildings: ['mielizny' => [BuildingType::Fort]],
        tick: 32,
        pending: struckAtThirty('mielizny'),
        depositTicks: ['mielizny' => 20],
    );

    $profile->ensureWorldState(fortSea());

    expect($profile->lastCounterattack()['stockpileLost'] ?? null)->toBe(['wood' => 15, 'steel' => 0, 'gold' => 0])
        ->and($profile->ownsIsland('mielizny'))->toBeTrue()
        ->and($profile->depositTicks()['mielizny'] ?? null)->toBe(32)
        // 15 po grabieży + 2 tiki urobku po uderzeniu
        ->and($profile->stockpile('mielizny'))->toEqual(new Resources(wood: 21));
});

it('po grabieży licznik spokoju startuje od nowa: następna zapowiedź najwcześniej 8 tików po uderzeniu', function () {
    $profile = garrisonCaptain(
        buildings: ['wyspa-sygnalowa' => [BuildingType::Fort]],
        tick: 30,
        pending: struckAtThirty(),
    );
    $profile->ensureWorldState(fortSea());
    expect($profile->lastCounterattack()['plundered'] ?? null)->toBeTrue();

    for ($i = 0; $i < 300 && null === $profile->counterattack(); ++$i) {
        garrisonTick($profile);
        if ($profile->tick() <= 38) {
            expect($profile->counterattack())->toBeNull();
        }
    }

    expect($profile->counterattack()['announcedAt'] ?? null)->toBeGreaterThan(30 + 8)
        ->and($profile->ownsIsland('wyspa-sygnalowa'))->toBeTrue();
});

it('fort na innej wyspie nie chroni celu: nieobecność odbiera wyspę jak w #78', function () {
    $profile = garrisonCaptain(
        owned: ['wyspa-sygnalowa', 'mielizny'],
        stockpiles: ['wyspa-sygnalowa' => new Resources(20, 10, 4)],
        buildings: ['mielizny' => [BuildingType::Fort]],
        tick: 32,
        pending: struckAtThirty(),
    );

    $profile->ensureWorldState(fortSea());

    expect($profile->ownsIsland('wyspa-sygnalowa'))->toBeFalse()
        ->and($profile->stockpile('wyspa-sygnalowa'))->toEqual(new Resources(10, 5, 2))
        ->and($profile->lastCounterattack()['plundered'] ?? null)->toBeFalse()
        ->and($profile->ownsIsland('mielizny'))->toBeTrue();
});

it('przy obecnej flocie obrona to zwykła bitwa: przegrana odbiera wyspę także z fortem', function () {
    $profile = garrisonCaptain(
        stockpiles: ['wyspa-sygnalowa' => new Resources(20, 10, 4)],
        buildings: ['wyspa-sygnalowa' => [BuildingType::Fort]],
        tick: 25,
        pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null],
    );
    $profile->ensureWorldState(fortSea());
    $kuter = $profile->fleet()[0];
    $gameId = GameId::new();
    $profile->startDefense('wyspa-sygnalowa', $gameId, [$kuter->id]);

    $outcome = $profile->settleBattle($gameId, 'lost', 15, Resources::none(), [$kuter->type->length()]);

    expect($outcome['defense'])->toEqual(['held' => false, 'stockpileLost' => new Resources(10, 5, 2)])
        ->and($outcome['lost'])->toBe([$kuter->type->value])
        ->and($profile->ownsIsland('wyspa-sygnalowa'))->toBeFalse()
        ->and($profile->lastCounterattack()['plundered'] ?? null)->toBeFalse()
        // fort stoi dalej, nieczynny do odbicia wyspy
        ->and($profile->buildingsOn('wyspa-sygnalowa'))->toBe([BuildingType::Fort])
        ->and($profile->hasActiveBuilding('wyspa-sygnalowa', BuildingType::Fort))->toBeFalse();
});

it('grabież przeżywa zapis i odczyt profilu, a rozstrzygnięcie sprzed fortu nie jest grabieżą', function () {
    $m = new ProfileSnapshotMapper();
    $profile = garrisonCaptain(buildings: ['wyspa-sygnalowa' => [BuildingType::Fort]], tick: 32, pending: struckAtThirty());
    $profile->ensureWorldState(fortSea());

    $state = $m->toArray($profile);
    expect($m->toDomain((string) $profile->id(), $state)->lastCounterattack())->toBe($profile->lastCounterattack());

    unset($state['counterattacks']['last']['plundered']);
    expect($m->toDomain((string) $profile->id(), $state)->lastCounterattack()['plundered'] ?? null)->toBeFalse();
});
