<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\GateBlock;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaEdge;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;

// Nawigacja oceaniczna, otwarty ocean i Morze Mgieł (#108).

const MIST = StaticSeaGraph::MIST_SEA;
const OPEN_OCEAN = StaticSeaGraph::OPEN_OCEAN;

function mistGraph(): StaticSeaGraph
{
    return new StaticSeaGraph(new StaticIslandCatalog());
}

function mistMap(string $seaId, int $worldSeed): WorldMap
{
    $graph = mistGraph();

    return $graph->sea($seaId)->map($worldSeed, $graph->passagesFrom($seaId));
}

/**
 * Kapitan na sektorze otwartego oceanu zadanego akwenu (albo na $position),
 * z odkrytym tylko sektorem pozycji.
 *
 * @param array{x:int,y:int}|null $position
 * @param list<Technology>        $researched
 */
function oceanCaptain(string $sea, array $researched = [], ?array $position = null, int $tick = 0): CaptainProfile
{
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $position ??= mistMap($sea, $seed)->gatePosition(OPEN_OCEAN);

    return CaptainProfile::fromSnapshot(
        $id, 'Jacek', 600, [], null, Resources::none(), $seed,
        $position, ["{$position['x']}:{$position['y']}"],
        tick: $tick,
        researched: $researched,
        sea: $sea,
    );
}

/** Sektor odległy o $dx w głąb mapy od zachodniej krawędzi i o $dy w pionie, w granicach mapy. */
function stepFrom(array $from, int $dx, int $dy): array
{
    return ['x' => $from['x'] + $dx, 'y' => $from['y'] + ($from['y'] + $dy <= 11 ? $dy : -$dy)];
}

it('za otwartym oceanem leży Morze Mgieł: motyw 10% / 0 / ×2 i 5 wysp od komandora do admirała', function () {
    $mist = mistGraph()->sea(MIST);

    expect($mist->name)->toBe('Morze Mgieł')
        ->and($mist->theme->toArray())->toBe(['stormChance' => 10, 'visibility' => 0, 'depositMultiplier' => 2])
        ->and(array_map(static fn (Island $i) => [$i->id, $i->requiredRank, $i->shipyardLevel, $i->depositYield->toArray()], $mist->islands->all()))->toBe([
            ['kotwicowisko-mgiel', Rank::Komandor, 3, ['wood' => 0, 'steel' => 0, 'gold' => 0]],
            ['wyspa-szeptow', Rank::Komandor, 0, ['wood' => 0, 'steel' => 0, 'gold' => 2]],
            ['mielizna-widm', Rank::Komandor, 0, ['wood' => 4, 'steel' => 0, 'gold' => 0]],
            ['archipelag-slepcow', Rank::Admiral, 0, ['wood' => 0, 'steel' => 3, 'gold' => 0]],
            ['cytadela-mgiel', Rank::Admiral, 0, ['wood' => 0, 'steel' => 0, 'gold' => 0]],
        ])
        // łupy i XP powyżej Oceanu Sztormów (najwyżej 200 XP)
        ->and(min(array_map(static fn (Island $i) => $i->xpWin, $mist->islands->all())))->toBeGreaterThan(200);
});

it('otwarty ocean łączy wschodnią krawędź Oceanu Sztormów z zachodnią krawędzią Morza Mgieł, bez strażnika', function () {
    $graph = mistGraph();
    $there = $graph->passage(StaticSeaGraph::STORM_OCEAN, OPEN_OCEAN);
    $back = $graph->passage(MIST, OPEN_OCEAN);

    expect($there->to)->toBe(MIST)
        ->and($there->edge)->toBe(SeaEdge::East)
        ->and($back->to)->toBe(StaticSeaGraph::STORM_OCEAN)
        ->and($back->edge)->toBe(SeaEdge::West)
        ->and($back->gate)->toBe($there->gate)
        ->and($there->gate->name)->toBe('Otwarty ocean')
        ->and($there->gate->guardian())->toBeNull()
        // z Morza Rozbitków na Morze Mgieł nie ma skrótu
        ->and($graph->passage(Sea::START, OPEN_OCEAN))->toBeNull();

    $seed = 12345;
    expect(mistMap(StaticSeaGraph::STORM_OCEAN, $seed)->gatePosition(OPEN_OCEAN)['x'])->toBe(11)
        ->and(mistMap(MIST, $seed)->gatePosition(OPEN_OCEAN)['x'])->toBe(0);
});

it('bez Nawigacji oceanicznej przejście przez otwarty ocean jest odrzucane w obie strony', function (string $from, string $to) {
    $profile = oceanCaptain($from, researched: [Technology::ZeglugaDwusektorowa]);
    $passage = mistGraph()->passage($from, OPEN_OCEAN);

    expect($profile->gateBlocker($passage))->toBe(GateBlock::Technology)
        ->and(fn () => $profile->passGate($passage, mistMap($from, $profile->worldSeed()), mistMap($to, $profile->worldSeed())))
        ->toThrow(DomainException::class, 'Gate locked: requires technology');
})->with([
    'Ocean → Mgły' => [StaticSeaGraph::STORM_OCEAN, MIST],
    'Mgły → Ocean' => [MIST, StaticSeaGraph::STORM_OCEAN],
]);

it('po zbadaniu Nawigacji oceanicznej flota przepływa na Morze Mgieł i z powrotem, po 1 tiku', function () {
    $profile = oceanCaptain(StaticSeaGraph::STORM_OCEAN, researched: [Technology::NawigacjaOceaniczna], tick: 5);
    $graph = mistGraph();
    $ocean = mistMap(StaticSeaGraph::STORM_OCEAN, $profile->worldSeed());
    $mist = mistMap(MIST, $profile->worldSeed());

    $outcome = $profile->passGate($graph->passage(StaticSeaGraph::STORM_OCEAN, OPEN_OCEAN), $ocean, $mist);
    $arrival = $mist->gatePosition(OPEN_OCEAN);

    // widoczność 0: odkryty tylko sektor bramy, na który flota wpłynęła
    expect($profile->currentSea())->toBe(MIST)
        ->and($profile->tick())->toBe(6)
        ->and($profile->position())->toBe($arrival)
        ->and($outcome['discoveredNow'])->toBe(["{$arrival['x']}:{$arrival['y']}"])
        ->and($profile->discoveredSectors())->toBe(["{$arrival['x']}:{$arrival['y']}"]);

    $profile->passGate($graph->passage(MIST, OPEN_OCEAN), $mist, $ocean);

    expect($profile->currentSea())->toBe(StaticSeaGraph::STORM_OCEAN)
        ->and($profile->tick())->toBe(7)
        ->and($profile->position())->toBe($ocean->gatePosition(OPEN_OCEAN));
});

it('na Morzu Mgieł rejs odkrywa tylko sektor docelowy', function () {
    $profile = oceanCaptain(MIST);
    $start = $profile->position();
    $target = stepFrom($start, 1, 1);

    $outcome = $profile->sail($target['x'], $target['y'], mistMap(MIST, $profile->worldSeed()));

    expect($outcome['discoveredNow'])->toBe(["{$target['x']}:{$target['y']}"])
        ->and($profile->discoveredSectors())->toHaveCount(2)
        // sąsiad celu zostaje w mgle
        ->and($profile->isDiscovered($start['x'] + 1, $start['y']))->toBeFalse();
});

it('Żegluga dwusektorowa na Morzu Mgieł odkrywa też sektor po drodze, a przy ruchu 2+1 ten po skosie', function (int $dx, int $dy, int $midDx, int $midDy) {
    $profile = oceanCaptain(MIST, researched: [Technology::ZeglugaDwusektorowa]);
    $start = $profile->position();
    $target = stepFrom($start, $dx, $dy);
    $mid = ['x' => $start['x'] + $midDx, 'y' => $start['y'] + ($target['y'] <=> $start['y']) * $midDy];

    $outcome = $profile->sail($target['x'], $target['y'], mistMap(MIST, $profile->worldSeed()));

    expect($outcome['discoveredNow'])->toBe(["{$mid['x']}:{$mid['y']}", "{$target['x']}:{$target['y']}"])
        ->and($profile->isVisited($mid['x'], $mid['y']))->toBeFalse()
        ->and($profile->tick())->toBe(1);
})->with([
    'prosto' => [2, 0, 1, 0],
    'po skosie' => [2, 2, 1, 1],
    '2+1' => [2, 1, 1, 1],
]);

it('złoża Morza Mgieł dają ×2', function () {
    $profile = oceanCaptain(MIST);
    $mist = mistGraph()->sea(MIST);

    expect($profile->depositYield($mist->islands->byId('wyspa-szeptow'), $mist->theme)->toArray())->toBe(['wood' => 0, 'steel' => 0, 'gold' => 4])
        ->and($profile->depositYield($mist->islands->byId('mielizna-widm'), $mist->theme)->toArray())->toBe(['wood' => 8, 'steel' => 0, 'gold' => 0]);
});
