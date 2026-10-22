<?php

declare(strict_types=1);

use App\Domain\Expedition\BuildingBlock;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\WorldMap;

function shipyardIsland(string $id): Island
{
    return (new StaticIslandCatalog())->byId($id) ?? throw new LogicException("No island $id");
}

/**
 * Kapitan z flotą startową zakotwiczony przy wyspie, z magazynem tej wyspy.
 *
 * @param list<string>                      $owned
 * @param array<string, list<BuildingType>> $buildings
 *
 * @return array{0: CaptainProfile, 1: WorldMap}
 */
function shipwright(
    string $at = 'zatoka-rozbitka',
    array $owned = ['zatoka-rozbitka'],
    ?Resources $stock = null,
    array $buildings = [],
): array {
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $islands = (new StaticIslandCatalog())->all();
    $world = WorldMap::generate($seed, array_map(static fn ($i) => $i->id, $islands), $islands);

    $profile = CaptainProfile::fromSnapshot(
        $id, 'Jacek', 80, [], null, Resources::none(), $seed, $world->islandPosition($at),
        stockpiles: null !== $stock ? [$at => $stock] : [],
        depositTicks: array_fill_keys($owned, 0),
        ownedIslands: array_fill_keys($owned, 0),
        buildings: $buildings,
    );

    return [$profile, $world];
}

it('rozbudowa w Zatoce kosztuje 50/30/15 i podnosi stocznię z poziomu 1 na 2', function () {
    $zatoka = shipyardIsland('zatoka-rozbitka');
    [$profile, $world] = shipwright(stock: new Resources(wood: 55, steel: 30, gold: 15));
    expect($profile->shipyardLevel($zatoka))->toBe(1);

    $profile->buildBuilding(BuildingType::RozbudowaStoczni, $zatoka, $world);

    expect($profile->shipyardLevel($zatoka))->toBe(2)
        ->and($profile->buildingsOn('zatoka-rozbitka'))->toBe([BuildingType::RozbudowaStoczni])
        ->and($profile->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 5, 'steel' => 0, 'gold' => 0])
        ->and($profile->depositYield($zatoka)->isZero())->toBeTrue();
});

it('bez bandery rozbudowa nie działa i stocznia wraca do poziomu z katalogu', function () {
    [$profile] = shipwright(owned: [], buildings: ['zatoka-rozbitka' => [BuildingType::RozbudowaStoczni]]);

    expect($profile->shipyardLevel(shipyardIsland('zatoka-rozbitka')))->toBe(1);
});

it('poziom stoczni bez rozbudowy to poziom z katalogu', function (string $island, int $level) {
    [$profile] = shipwright(owned: [$island]);

    expect($profile->shipyardLevel(shipyardIsland($island)))->toBe($level);
})->with([
    'Zatoka' => ['zatoka-rozbitka', 1],
    'Sygnałowa' => ['wyspa-sygnalowa', 3],
    'Mielizny bez stoczni' => ['mielizny', 0],
]);

it('rozbudowa jest odrzucana na Sygnałowej (poziom 3) i na wyspach bez stoczni', function (string $island) {
    [$profile, $world] = shipwright(at: $island, owned: [$island], stock: new Resources(100, 100, 100));

    expect($profile->buildingBlocker(BuildingType::RozbudowaStoczni, shipyardIsland($island), $world))->toBe(BuildingBlock::Site)
        ->and(fn () => $profile->buildBuilding(BuildingType::RozbudowaStoczni, shipyardIsland($island), $world))
        ->toThrow(DomainException::class, 'Building site not suitable');
})->with(['wyspa-sygnalowa', 'mielizny', 'archipelag-mgiel']);

it('rozbudowa podlega regułom budynku: bandera, jedna na wyspę, obecność, koszt', function (array $shipwright, string $message) {
    [$profile, $world] = shipwright(...$shipwright);

    expect(fn () => $profile->buildBuilding(BuildingType::RozbudowaStoczni, shipyardIsland('zatoka-rozbitka'), $world))
        ->toThrow(DomainException::class, $message);
})->with([
    'bez bandery' => [['owned' => [], 'stock' => new Resources(100, 100, 100)], 'Island not owned'],
    'druga rozbudowa' => [['stock' => new Resources(100, 100, 100), 'buildings' => ['zatoka-rozbitka' => [BuildingType::RozbudowaStoczni]]], 'Building already standing'],
    'kapitan poza wyspą' => [['at' => 'mielizny', 'stock' => new Resources(100, 100, 100)], 'Not at island'],
    'brak złota' => [['stock' => new Resources(wood: 50, steel: 30, gold: 14)], 'Not enough resources'],
]);
