<?php

declare(strict_types=1);

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;

// Kontrataki i pełna bandera na wielu akwenach (#109): cel z całego świata,
// rozstrzygnięcie na akwenie wyspy, bandera liczona osobno na każdym akwenie.

const MULTI_SEED = 7;

/** Mapa akwenu gry ze znajomością pozostałych akwenów — jak z WorldFactory. */
function worldSeaMap(string $seaId): WorldMap
{
    $graph = new StaticSeaGraph(new StaticIslandCatalog());
    $maps = [];
    foreach ($graph->seas() as $sea) {
        $maps[$sea->id] = $sea->map(MULTI_SEED, $graph->passagesFrom($sea->id));
    }

    return $maps[$seaId]->withOtherSeas(...array_values($maps));
}

/** Mapa jednego akwenu bez sąsiadów — świat sprzed akwenów. */
function lonelySeaMap(string $seaId): WorldMap
{
    return worldSeaMap($seaId)->withOtherSeas();
}

/** @return list<string> wyspy akwenu gry */
function seaIslandIds(string $seaId): array
{
    return array_keys(worldSeaMap($seaId)->islands());
}

/**
 * Kapitan z flotą na wyspie danego akwenu, z rzutami kontrataków rozegranymi do tiku 0.
 *
 * @param list<string>                                                              $owned
 * @param array<string, list<BuildingType>>                                         $buildings
 * @param array{island:string, announcedAt:int, strikeAt:int, defense:?string}|null $pending
 */
function multiSeaCaptain(
    array $owned,
    int $tick,
    string $sea = Sea::START,
    ?string $at = null,
    array $buildings = [],
    ?array $pending = null,
    ?ProfileId $id = null,
): CaptainProfile {
    $map = worldSeaMap($sea);
    $position = $map->islandPosition($at ?? array_key_first($map->islands()));

    return CaptainProfile::fromSnapshot(
        $id ?? ProfileId::new(), 'Jacek', 400, [], null, Resources::none(), MULTI_SEED, $position,
        ["{$position['x']}:{$position['y']}"],
        tick: $tick,
        depositTicks: array_fill_keys($owned, 0),
        ownedIslands: array_fill_keys($owned, 0),
        counterattacks: ['pending' => $pending, 'calmFrom' => 0, 'rolledTo' => 0, 'last' => null],
        buildings: $buildings,
        sea: $sea,
    );
}

it('mapa zna pozostałe akweny: wyspę znajdzie na jej własnym akwenie', function () {
    $here = worldSeaMap(Sea::START);

    expect($here->seaOf('mielizny')?->seaId())->toBe(Sea::START)
        ->and($here->seaOf('rafa-kotwiczna')?->seaId())->toBe(StaticSeaGraph::STORM_OCEAN)
        ->and($here->seaOf('nieznana'))->toBeNull()
        // mapa bez sąsiadów zna tylko swój akwen, a dołączone mapy nie niosą dalej swoich
        ->and(lonelySeaMap(Sea::START)->seaOf('rafa-kotwiczna'))->toBeNull()
        ->and($here->otherSeas()[0]->otherSeas())->toBe([]);
});

it('kontratak trafia w wyspę innego akwenu niż ten, na którym stoi flota', function () {
    // flota na Morzu Rozbitków, pod banderą tylko Rafa Kotwiczna z Oceanu; 200 tików rzutów
    $profile = multiSeaCaptain(owned: ['rafa-kotwiczna'], tick: 200);
    $profile->ensureWorldState(worldSeaMap(Sea::START));

    $last = $profile->lastCounterattack();
    expect($last['island'] ?? null)->toBe('rafa-kotwiczna')
        ->and($last['absent'])->toBeTrue()
        ->and($last['held'])->toBeFalse()
        ->and($profile->ownsIsland('rafa-kotwiczna'))->toBeFalse();

    // świat jednego akwenu nie zna wyspy Oceanu — nie atakuje jej, jak przed #109
    $lonely = multiSeaCaptain(owned: ['rafa-kotwiczna'], tick: 200);
    $lonely->ensureWorldState(lonelySeaMap(Sea::START));
    expect($lonely->lastCounterattack())->toBeNull()
        ->and($lonely->ownsIsland('rafa-kotwiczna'))->toBeTrue();
});

it('przystań innego akwenu nie jest celem także przy znajomości całego świata', function () {
    $profile = multiSeaCaptain(owned: ['zatoka-rozbitka'], tick: 200, sea: StaticSeaGraph::STORM_OCEAN);
    $profile->ensureWorldState(worldSeaMap(StaticSeaGraph::STORM_OCEAN));

    expect($profile->counterattack())->toBeNull()
        ->and($profile->lastCounterattack())->toBeNull()
        ->and($profile->ownsIsland('zatoka-rozbitka'))->toBeTrue();
});

it('rzut kontrataku nie zależy od akwenu floty', function () {
    $id = ProfileId::new();
    $owned = ['mielizny', 'archipelag-mgiel', 'rafa-kotwiczna', 'wyspa-latarnikow'];
    $west = multiSeaCaptain(owned: $owned, tick: 90, id: $id);
    $east = multiSeaCaptain(owned: $owned, tick: 90, sea: StaticSeaGraph::STORM_OCEAN, id: $id);

    $west->ensureWorldState(worldSeaMap(Sea::START));
    $east->ensureWorldState(worldSeaMap(StaticSeaGraph::STORM_OCEAN));

    expect($west->counterattackState())->toBe($east->counterattackState())
        ->and($west->ownedIslands())->toBe($east->ownedIslands())
        ->and($west->lastCounterattack())->not->toBeNull();
});

it('na profilu z jednym akwenem kontrataki idą jak dotąd', function () {
    $id = ProfileId::new();
    $owned = ['mielizny', 'wyspa-sygnalowa', 'archipelag-mgiel'];
    $before = multiSeaCaptain(owned: $owned, tick: 120, id: $id);
    $now = multiSeaCaptain(owned: $owned, tick: 120, id: $id);

    $before->ensureWorldState(lonelySeaMap(Sea::START));
    $now->ensureWorldState(worldSeaMap(Sea::START));

    expect($now->counterattackState())->toBe($before->counterattackState())
        ->and($now->ownedIslands())->toBe($before->ownedIslands())
        ->and($now->log())->toEqual($before->log());
});

it('uderzenie na wyspę innego akwenu nalicza jej złoże z motywu jej akwenu i zabiera połowę magazynu', function () {
    $pending = ['island' => 'rafa-kotwiczna', 'announcedAt' => 0, 'strikeAt' => 12, 'defense' => null];
    $profile = multiSeaCaptain(owned: ['rafa-kotwiczna'], tick: 20, pending: $pending);
    $ocean = worldSeaMap(StaticSeaGraph::STORM_OCEAN);
    $rafa = $ocean->deposits()['rafa-kotwiczna'];
    // urobek 12 tików Oceanu (złoża ×2), do limitu — liczony, zanim wyspa przepadnie
    $mined = min($profile->depositYield($rafa, $ocean->theme())->steel * 12, $profile->depositCap($rafa, $ocean->theme())->steel);

    $profile->ensureWorldState(worldSeaMap(Sea::START));

    expect($profile->ownsIsland('rafa-kotwiczna'))->toBeFalse()
        ->and($mined)->toBeGreaterThan(12 * $rafa->depositYield->steel)
        ->and($profile->lastCounterattack()['stockpileLost']['steel'])->toBe(intdiv($mined, 2))
        ->and($profile->stockpile('rafa-kotwiczna')->steel)->toBe($mined - intdiv($mined, 2))
        ->and($profile->lastCounterattack()['tick'])->toBe(12);
});

it('fort chroni wyspę innego akwenu, gdy flota stoi daleko', function () {
    $pending = ['island' => 'rafa-kotwiczna', 'announcedAt' => 0, 'strikeAt' => 12, 'defense' => null];
    $profile = multiSeaCaptain(
        owned: ['rafa-kotwiczna'], tick: 20, pending: $pending,
        buildings: ['rafa-kotwiczna' => [BuildingType::Fort]],
    );

    $profile->ensureWorldState(worldSeaMap(Sea::START));

    expect($profile->ownsIsland('rafa-kotwiczna'))->toBeTrue()
        ->and($profile->lastCounterattack()['plundered'])->toBeTrue()
        ->and($profile->lastCounterattack()['absent'])->toBeTrue()
        ->and($profile->counterattack())->toBeNull();
});

it('pełna bandera liczy się osobno na każdym akwenie', function () {
    $shipwreck = seaIslandIds(Sea::START);
    $oceanIds = seaIslandIds(StaticSeaGraph::STORM_OCEAN);
    $west = worldSeaMap(Sea::START);
    $east = worldSeaMap(StaticSeaGraph::STORM_OCEAN);

    $profile = multiSeaCaptain(owned: [...$shipwreck, $oceanIds[0]], tick: 0);
    expect($profile->hasFullFlag($west))->toBeTrue()
        ->and($profile->hasFullFlag($east))->toBeFalse();

    $everywhere = multiSeaCaptain(owned: [...$shipwreck, ...$oceanIds], tick: 0);
    expect($everywhere->hasFullFlag($west))->toBeTrue()
        ->and($everywhere->hasFullFlag($east))->toBeTrue();

    $oceanOnly = multiSeaCaptain(owned: $oceanIds, tick: 0);
    expect($oceanOnly->hasFullFlag($west))->toBeFalse()
        ->and($oceanOnly->hasFullFlag($east))->toBeTrue();
});
