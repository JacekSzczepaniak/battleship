<?php

declare(strict_types=1);

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\GateBlock;
use App\Domain\Expedition\GateCondition;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\IslandCatalog;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaEdge;
use App\Domain\Expedition\SeaGate;
use App\Domain\Expedition\SeaPassage;
use App\Domain\Expedition\SeaState;
use App\Domain\Expedition\SeaTheme;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldIslandCatalog;
use App\Domain\Expedition\WorldMap;

// Akweny (#106): graf świata, stan profilu per akwen, motyw akwenu.

const TEST_SEA = 'akwen-testowy';

/** Katalog z jedną wyspą o danym id i złożu. */
function seaIslands(string $id, Resources $deposit = new Resources()): IslandCatalog
{
    return new class(new Island($id, $id, '', Rank::Rozbitek, 'classic', 10, 5, new Resources(wood: 5), new Resources(wood: 1), 1, 7, 7, $deposit)) implements IslandCatalog {
        public function __construct(private Island $island)
        {
        }

        public function all(): array
        {
            return [$this->island];
        }

        public function byId(string $id): ?Island
        {
            return $this->island->id === $id ? $this->island : null;
        }
    };
}

/** Akwen testowy z motywem Oceanu Sztormów, ale bez widoczności: sztorm 40%, widoczność 0, złoża ×2. */
function testSea(): Sea
{
    return new Sea(TEST_SEA, 'Akwen testowy', new SeaTheme(stormChance: 40, visibility: 0, depositMultiplier: 2), new StaticIslandCatalog());
}

/**
 * Kapitan na akwenie testowym, zakotwiczony przy wyspie, z wyspami pod banderą od tiku 0.
 *
 * @param list<string>                      $owned
 * @param array<string, list<BuildingType>> $buildings
 * @param list<Technology>                  $researched
 *
 * @return array{0: CaptainProfile, 1: WorldMap}
 */
function seafarer(
    string $at = 'mielizny',
    array $owned = [],
    array $buildings = [],
    array $researched = [],
    int $tick = 0,
    ?array $position = null,
): array {
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $world = testSea()->map($seed);

    $profile = CaptainProfile::fromSnapshot(
        $id, 'Jacek', 300, [], null, Resources::none(), $seed, $position ?? $world->islandPosition($at),
        tick: $tick,
        depositTicks: array_fill_keys($owned, 0),
        ownedIslands: array_fill_keys($owned, 0),
        buildings: $buildings,
        researched: $researched,
        sea: TEST_SEA,
    );

    return [$profile, $world];
}

it('Morze Rozbitków to start grafu z motywem 20% / 1 / ×1', function () {
    $graph = new StaticSeaGraph(new StaticIslandCatalog());
    $start = $graph->start();

    expect($start->id)->toBe(Sea::START)
        ->and($start->name)->toBe('Morze Rozbitków')
        ->and($start->theme->toArray())->toBe(['stormChance' => 20, 'visibility' => 1, 'depositMultiplier' => 1])
        ->and($graph->sea(Sea::START))->toBe($start)
        ->and($graph->sea('morze-nieznane'))->toBeNull();
});

it('mapa Morza Rozbitków to dawna mapa świata z seeda profilu, więc stare profile nie tracą wysp', function () {
    $islands = (new StaticIslandCatalog())->all();
    $sea = (new StaticSeaGraph(new StaticIslandCatalog()))->start();

    foreach ([1, 7, 2026, 123456789] as $worldSeed) {
        $legacy = WorldMap::generate($worldSeed, array_map(static fn ($i) => $i->id, $islands), $islands);
        $map = $sea->map($worldSeed);

        expect($sea->seed($worldSeed))->toBe($worldSeed)
            ->and($map->islands())->toBe($legacy->islands())
            ->and(array_keys($map->deposits()))->toBe(array_keys($legacy->deposits()))
            ->and($map->seaId())->toBe(Sea::START);
    }
});

it('inny akwen ma własny seed wyprowadzony z seeda świata i id akwenu', function () {
    $sea = testSea();

    expect($sea->seed(2026))->toBe($sea->seed(2026))
        ->and($sea->seed(2026))->not->toBe(2026)
        ->and($sea->seed(2026))->not->toBe($sea->seed(2027))
        ->and($sea->map(2026)->seaId())->toBe(TEST_SEA)
        ->and($sea->map(2026)->theme()->stormChance)->toBe(40);
});

it('graf pilnuje unikalnych wysp i przejść do znanych akwenów; katalog świata zna wyspy wszystkich akwenów', function () {
    $shipwreck = new StaticIslandCatalog();
    $rozbitkow = new Sea(Sea::START, 'Morze Rozbitków', new SeaTheme(), $shipwreck);
    $ocean = new Sea('ocean-sztormow', 'Ocean Sztormów', new SeaTheme(40, 1, 2), seaIslands('rafa-sztormowa'));

    $gate = new SeaGate('brama', 'Brama', new class implements GateCondition {
        public function blocker(CaptainProfile $profile, string $gateId): ?GateBlock
        {
            return null;
        }
    });
    $there = new SeaPassage(Sea::START, 'ocean-sztormow', $gate, SeaEdge::East);
    $graph = new StaticSeaGraph($shipwreck, [$rozbitkow, $ocean], [$there, new SeaPassage('ocean-sztormow', Sea::START, $gate, SeaEdge::West)]);
    $world = new WorldIslandCatalog($graph);

    expect($graph->passagesFrom(Sea::START))->toBe([$there])
        ->and($graph->passagesFrom('ocean-sztormow'))->toHaveCount(1)
        ->and($world->all())->toHaveCount(7)
        ->and($world->byId('rafa-sztormowa')?->id)->toBe('rafa-sztormowa')
        ->and($world->byId('mielizny')?->id)->toBe('mielizny')
        ->and(fn () => new StaticSeaGraph($shipwreck, [$rozbitkow, new Sea('kopia', 'Kopia', new SeaTheme(), seaIslands('mielizny'))]))
        ->toThrow(InvalidArgumentException::class, 'Island ids must be unique across seas')
        ->and(fn () => new StaticSeaGraph($shipwreck, [$rozbitkow], [new SeaPassage(Sea::START, 'morze-mgiel', $gate, SeaEdge::East)]))
        ->toThrow(InvalidArgumentException::class, 'Sea passage connects an unknown sea')
        // brama działa w obie strony, więc przejście bez powrotnego jest błędem grafu
        ->and(fn () => new StaticSeaGraph($shipwreck, [$rozbitkow, $ocean], [$there]))
        ->toThrow(InvalidArgumentException::class, 'Sea gate needs a passage back');
});

it('widoczność 0 odkrywa tylko sektor floty: na starcie i po rejsie', function () {
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $world = testSea()->map($seed);
    $profile = CaptainProfile::fromSnapshot($id, 'Jacek', 0, [], null, Resources::none(), $seed, sea: TEST_SEA);

    $profile->ensureWorldState($world);
    $start = $profile->position();
    expect($profile->discoveredSectors())->toBe(["{$start['x']}:{$start['y']}"]);

    $target = ['x' => $start['x'] + ($start['x'] < WorldMap::WIDTH - 1 ? 1 : -1), 'y' => $start['y']];
    $outcome = $profile->sail($target['x'], $target['y'], $world);

    expect($outcome['discoveredNow'])->toBe(["{$target['x']}:{$target['y']}"])
        ->and($profile->discoveredSectors())->toHaveCount(2)
        ->and($profile->isDiscovered($target['x'], $target['y'] + ($target['y'] > 0 ? -1 : 1)))->toBeFalse();
});

it('mnożnik złóż ×2 podwaja urobek, a tartak podwaja już powiększony urobek', function () {
    $mielizny = (new StaticIslandCatalog())->byId('mielizny');
    [$plain, $world] = seafarer(owned: ['mielizny']);
    [$sawmill] = seafarer(owned: ['mielizny'], buildings: ['mielizny' => [BuildingType::Tartak]]);
    $theme = $world->theme();

    // Mielizny: 3 drewna na tik na Morzu Rozbitków
    expect($plain->depositYield($mielizny)->toArray())->toBe(['wood' => 3, 'steel' => 0, 'gold' => 0])
        ->and($plain->depositYield($mielizny, $theme)->toArray())->toBe(['wood' => 6, 'steel' => 0, 'gold' => 0])
        ->and($sawmill->depositYield($mielizny, $theme)->toArray())->toBe(['wood' => 12, 'steel' => 0, 'gold' => 0])
        ->and($plain->depositCap($mielizny, $theme)->toArray())->toBe(['wood' => 60, 'steel' => 0, 'gold' => 0]);
});

it('kopalnia podwaja urobek złoża powiększony mnożnikiem akwenu', function () {
    $archipelag = (new StaticIslandCatalog())->byId('archipelag-mgiel');
    [$mine, $world] = seafarer(at: 'archipelag-mgiel', owned: ['archipelag-mgiel'], buildings: ['archipelag-mgiel' => [BuildingType::Kopalnia]]);

    // Archipelag Mgieł: 2 stali na tik → ×2 akwenu → ×2 kopalni
    expect($mine->depositYield($archipelag, $world->theme())->toArray())->toBe(['wood' => 0, 'steel' => 8, 'gold' => 0]);
});

it('złoże pod banderą na akwenie ×2 nalicza do magazynu podwojony urobek', function () {
    [$profile, $world] = seafarer(owned: ['mielizny'], tick: 4);

    $profile->ensureWorldState($world);

    // 4 tiki × 3 drewna × 2
    expect($profile->stockpile('mielizny')->toArray())->toBe(['wood' => 24, 'steel' => 0, 'gold' => 0]);
});

it('sztorm 40% z Mapami sztormowymi daje 20%; Morze Rozbitków dalej 20% i 10%', function () {
    [$plain, $world] = seafarer();
    [$charted] = seafarer(researched: [Technology::MapySztormowe]);

    expect($plain->stormChance($world->theme()))->toBe(40)
        ->and($charted->stormChance($world->theme()))->toBe(20)
        ->and($plain->stormChance())->toBe(20)
        ->and($charted->stormChance())->toBe(10);
});

it('rejs na akwenie 40% łapie sztorm, który przy 20% by ominął', function () {
    // seed i tik, dla których rzut sztormu wypada między 20 a 39: sztorm tylko przy szansie 40%
    $found = null;
    for ($i = 0; $i < 500 && null === $found; ++$i) {
        [$profile, $world] = seafarer();
        $roll = crc32("{$profile->worldSeed()}:1") % 100;
        if ($roll >= 20 && $roll < 40) {
            $found = [$profile, $world];
        }
    }
    expect($found)->not->toBeNull();
    [$profile, $world] = $found;

    $profile->ensureWorldState($world);
    $start = $profile->position();
    $outcome = $profile->sail($start['x'] + ($start['x'] < WorldMap::WIDTH - 1 ? 1 : -1), $start['y'], $world);

    expect($outcome['event']['type'] ?? null)->toBe('storm');
});

it('stan akwenu jest osobny: rejs zmienia tylko akwen floty, a mapa innego akwenu jest odrzucana', function () {
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $world = testSea()->map($seed);
    $home = new SeaState(['x' => 2, 'y' => 3], ['2:3', '3:3'], ['2:3']);
    $profile = CaptainProfile::fromSnapshot($id, 'Jacek', 0, [], null, Resources::none(), $seed, sea: TEST_SEA, seaStates: [Sea::START => $home]);

    $profile->ensureWorldState($world);
    $start = $profile->position();
    $profile->sail($start['x'] + ($start['x'] < WorldMap::WIDTH - 1 ? 1 : -1), $start['y'], $world);

    expect($profile->currentSea())->toBe(TEST_SEA)
        ->and($profile->visitedSectors())->toHaveCount(2)
        ->and($profile->seaStates()[Sea::START]->position())->toBe(['x' => 2, 'y' => 3])
        ->and($profile->seaStates()[Sea::START]->discoveredSectors())->toBe(['2:3', '3:3'])
        ->and($profile->seaStates()[Sea::START]->visitedSectors())->toBe(['2:3'])
        ->and(fn () => $profile->ensureWorldState(WorldMap::generate($seed, ['zatoka-rozbitka'])))
        ->toThrow(LogicException::class);
});
