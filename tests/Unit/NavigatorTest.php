<?php

declare(strict_types=1);

use App\Application\Expedition\NavigatorRoute;
use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\ResearchBlock;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaState;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\TechBranch;
use App\Domain\Expedition\TechLevel;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;

// Nawigator (#153): trasa po odkrytych sektorach i przez otwarte bramy,
// koszt jak suma zwykłych rejsów, przełącznik omijania sztormów.

const NAV_SEED = 7;

function navGraph(): StaticSeaGraph
{
    return new StaticSeaGraph(new StaticIslandCatalog());
}

function navRoute(): NavigatorRoute
{
    $graph = navGraph();

    return new NavigatorRoute(new WorldFactory($graph), $graph);
}

/**
 * Kapitan na Morzu Rozbitków z ręcznie odkrytą mapą.
 *
 * @param array{x:int,y:int}      $at
 * @param list<string>            $discovered  sektory "x:y" (pozycja dochodzi sama)
 * @param list<string>            $visited     sektory "x:y" (pozycja dochodzi sama)
 * @param list<Technology>        $researched
 * @param array<string, SeaState> $seaStates
 * @param array<string, int>      $openedGates
 */
function navCaptain(
    array $at,
    array $discovered,
    array $visited = [],
    array $researched = [Technology::MapySztormowe, Technology::Nawigator],
    array $seaStates = [],
    array $openedGates = [],
    int $tick = 0,
): CaptainProfile {
    $here = "{$at['x']}:{$at['y']}";

    return CaptainProfile::fromSnapshot(
        ProfileId::new(), 'Jacek', 1000, [], [OwnedShip::build(ShipType::Kuter)], Resources::none(), NAV_SEED, $at,
        array_values(array_unique([$here, ...$discovered])),
        tick: $tick,
        visited: array_values(array_unique([$here, ...$visited])),
        researched: $researched,
        sea: Sea::START,
        seaStates: $seaStates,
        openedGates: $openedGates,
    );
}

/**
 * @param array{steps: list<array{x:int,y:int}>}|null $plan
 *
 * @return list<string> sektory kroków jako "x:y"
 */
function navCells(?array $plan): array
{
    return array_map(static fn (array $step) => "{$step['x']}:{$step['y']}", $plan['steps'] ?? []);
}

it('Nawigator to poziom 2 gałęzi Nawigacja: wymaga Map sztormowych i rangi sternik', function () {
    $level = Technology::Nawigator->academyLevel();

    expect(Technology::Nawigator->branch())->toBe(TechBranch::Nawigacja)
        ->and($level)->toBe(TechLevel::Second)
        ->and($level->researchCost()->toArray())->toBe(['wood' => 40, 'steel' => 30, 'gold' => 15])
        ->and($level->researchTicks())->toBe(10)
        ->and($level->treeBlocker(TechBranch::Nawigacja, [], Rank::Sternik))->toBe(ResearchBlock::Prerequisite)
        ->and($level->treeBlocker(TechBranch::Nawigacja, [Technology::MapySztormowe], Rank::Majtek))->toBe(ResearchBlock::Rank)
        ->and($level->treeBlocker(TechBranch::Nawigacja, [Technology::MapySztormowe], Rank::Sternik))->toBeNull();
});

it('trasa omija nieodkryty sektor, a koszt w tikach to liczba rejsów', function () {
    // rząd 5 odkryty bez (2,5); obejście po skosie przez (2,4)
    $profile = navCaptain(['x' => 0, 'y' => 5], ['1:5', '2:4', '3:5', '4:5']);

    $plan = navRoute()->plan($profile, Sea::START, 4, 5, false);

    expect(navCells($plan))->toBe(['1:5', '2:4', '3:5', '4:5'])
        ->and($plan['ticks'] ?? null)->toBe(4)
        ->and(array_column($plan['steps'] ?? [], 'type'))->toBe(['sail', 'sail', 'sail', 'sail']);
});

it('bez odkrytej drogi i do nieodkrytego celu trasy nie ma', function () {
    $profile = navCaptain(['x' => 0, 'y' => 5], ['1:5', '3:5', '4:5']);

    expect(navRoute()->plan($profile, Sea::START, 4, 5, false))->toBeNull()
        ->and(navRoute()->plan($profile, Sea::START, 6, 5, false))->toBeNull();
});

it('flota na celu: pusta trasa za 0 tików', function () {
    $profile = navCaptain(['x' => 3, 'y' => 3], []);

    expect(navRoute()->plan($profile, Sea::START, 3, 3, false))->toBe(['steps' => [], 'ticks' => 0, 'stormSectors' => 0]);
});

it('z Żeglugą dwusektorową kroki mają do 2 sektorów, a sektor pośredni musi być odkryty', function () {
    $row = ['1:5', '2:5', '3:5', '4:5'];
    $two = [Technology::MapySztormowe, Technology::ZeglugaDwusektorowa, Technology::Nawigator];

    $plan = navRoute()->plan(navCaptain(['x' => 0, 'y' => 5], $row, researched: $two), Sea::START, 4, 5, false);
    expect(navCells($plan))->toBe(['2:5', '4:5']);

    // bez (1,5) skok o 2 z (0,5) nie przejdzie, a krótszej drogi przez odkryte sektory nie ma
    $gap = navRoute()->plan(navCaptain(['x' => 0, 'y' => 5], ['2:5', '3:5', '4:5'], researched: $two), Sea::START, 4, 5, false);
    expect($gap)->toBeNull();
});

it('domyślnie najkrótsza trasa, a przy remisie tików ta z mniejszą liczbą sektorów ze sztormem', function () {
    // (1,4) i (1,5) prowadzą do celu w 2 tikach; odwiedzony jest tylko (1,4)
    $profile = navCaptain(['x' => 0, 'y' => 5], ['1:4', '1:5', '2:5'], visited: ['1:4', '2:5']);

    $plan = navRoute()->plan($profile, Sea::START, 2, 5, false);

    expect(navCells($plan))->toBe(['1:4', '2:5'])
        ->and($plan['stormSectors'] ?? null)->toBe(0);
});

it('przy remisie tików i sztormów trasa omija dokowanie na wyspie po drodze', function () {
    $graph = navGraph();
    $map = ($graph->sea(Sea::START) ?? throw new LogicException())->map(NAV_SEED, $graph->passagesFrom(Sea::START));
    $island = array_values(array_filter($map->islands(), static fn (array $p) => $p['x'] >= 1 && $p['x'] <= 10 && $p['y'] >= 1))[0]
        ?? throw new LogicException();
    ['x' => $x, 'y' => $y] = $island;
    // na wprost przez wyspę i obok niej po wodzie: po 2 tiki, wszystko odwiedzone
    $sectors = ["$x:$y", "$x:".($y - 1), ($x + 1).":$y"];
    $profile = navCaptain(['x' => $x - 1, 'y' => $y], $sectors, visited: $sectors);

    $plan = navRoute()->plan($profile, Sea::START, $x + 1, $y, false);

    expect(navCells($plan))->toBe(["$x:".($y - 1), ($x + 1).":$y"])
        ->and(array_column($plan['steps'] ?? [], 'dock'))->toBe([false, null !== $map->islandAt($x + 1, $y)]);
});

it('przełącznik omija nieodwiedzone sektory kosztem dłuższej trasy', function () {
    // najkrótsza (2 tiki) idzie przez nieodwiedzony (1,4..6); bezpieczna okrąża je od góry
    $profile = navCaptain(
        ['x' => 0, 'y' => 5],
        ['1:4', '1:5', '1:6', '0:4', '1:3', '2:4', '2:5'],
        visited: ['0:4', '1:3', '2:4', '2:5'],
    );

    $short = navRoute()->plan($profile, Sea::START, 2, 5, false);
    $safe = navRoute()->plan($profile, Sea::START, 2, 5, true);

    expect($short['ticks'] ?? null)->toBe(2)
        ->and($short['stormSectors'] ?? null)->toBe(1)
        ->and(navCells($safe))->toBe(['0:4', '1:3', '2:4', '2:5'])
        ->and($safe['stormSectors'] ?? null)->toBe(0);
});

it('z przełącznikiem trasy bez ryzyka nie ma, gdy cel jest nieodwiedzony', function () {
    $profile = navCaptain(['x' => 0, 'y' => 5], ['1:5', '2:5'], visited: ['1:5']);

    expect(navRoute()->plan($profile, Sea::START, 2, 5, true))->toBeNull()
        ->and(navRoute()->plan($profile, Sea::START, 2, 5, false))->not->toBeNull();
});

/**
 * Kapitan obok cieśniny na Morzu Rozbitków, z odkrytym sektorem za bramą
 * i jego sąsiadem na Oceanie Sztormów.
 *
 * @return array{0: CaptainProfile, 1: string, 2: array{x:int,y:int}, 3: array{x:int,y:int}, 4: array{x:int,y:int}}
 */
function navStraitCaptain(bool $open): array
{
    $graph = navGraph();
    $maps = [];
    foreach ($graph->seas() as $sea) {
        $maps[$sea->id] = $sea->map(NAV_SEED, $graph->passagesFrom($sea->id));
    }
    $gate = (string) array_key_first($maps[Sea::START]->gates());
    $gatePos = $maps[Sea::START]->gatePosition($gate) ?? throw new LogicException();
    $arrival = $maps[StaticSeaGraph::STORM_OCEAN]->gatePosition($gate) ?? throw new LogicException();
    // brama cieśniny leży na wschodniej krawędzi Morza Rozbitków, a na zachodniej Oceanu
    $at = ['x' => $gatePos['x'] - 1, 'y' => $gatePos['y']];
    $target = ['x' => $arrival['x'] + 1, 'y' => $arrival['y']];

    $ocean = new SeaState(null, ["{$arrival['x']}:{$arrival['y']}", "{$target['x']}:{$target['y']}"], ["{$arrival['x']}:{$arrival['y']}"]);
    $profile = navCaptain(
        $at,
        ["{$gatePos['x']}:{$gatePos['y']}"],
        visited: ["{$gatePos['x']}:{$gatePos['y']}"],
        seaStates: [StaticSeaGraph::STORM_OCEAN => $ocean],
        openedGates: $open ? [$gate => 0] : [],
    );

    return [$profile, $gate, $gatePos, $arrival, $target];
}

it('trasa prowadzi przez otwartą bramę na odkryty sektor innego akwenu', function () {
    [$profile, $gate, $gatePos, $arrival, $target] = navStraitCaptain(true);

    $plan = navRoute()->plan($profile, StaticSeaGraph::STORM_OCEAN, $target['x'], $target['y'], false);

    expect($plan['steps'] ?? null)->toBe([
        ['type' => 'sail', 'sea' => Sea::START, 'x' => $gatePos['x'], 'y' => $gatePos['y'], 'gate' => null, 'risk' => false, 'dock' => false],
        ['type' => 'gate', 'sea' => StaticSeaGraph::STORM_OCEAN, 'x' => $arrival['x'], 'y' => $arrival['y'], 'gate' => $gate, 'risk' => false, 'dock' => false],
        ['type' => 'sail', 'sea' => StaticSeaGraph::STORM_OCEAN, 'x' => $target['x'], 'y' => $target['y'], 'gate' => null, 'risk' => true, 'dock' => false],
    ])->and($plan['ticks'] ?? null)->toBe(3)
        ->and($plan['stormSectors'] ?? null)->toBe(1);
});

it('zamknięta brama nie prowadzi trasy', function () {
    [$profile, , , , $target] = navStraitCaptain(false);

    expect(navRoute()->plan($profile, StaticSeaGraph::STORM_OCEAN, $target['x'], $target['y'], false))->toBeNull();
});

it('przepłynięcie trasy zwykłymi rejsami i bramą kosztuje dokładnie tyle tików, ile podał plan', function () {
    [$profile, $gate, , , $target] = navStraitCaptain(true);
    $plan = navRoute()->plan($profile, StaticSeaGraph::STORM_OCEAN, $target['x'], $target['y'], false) ?? throw new LogicException();
    $graph = navGraph();
    $factory = new WorldFactory($graph);
    $before = $profile->tick();

    foreach ($plan['steps'] as $step) {
        $world = $factory->worldFor($profile);
        if ('gate' === $step['type']) {
            $passage = $graph->passage($profile->currentSea(), $gate) ?? throw new LogicException();
            $profile->passGate($passage, $world, $factory->mapOf($graph->sea($passage->to) ?? throw new LogicException(), $profile));
        } else {
            $outcome = $profile->sail($step['x'], $step['y'], $world);
            // seed i tiki dobrane tak, że po drodze nie ma spotkania, które wstrzymałoby rejs
            expect($outcome['encounter'])->toBeNull();
        }
    }

    expect($profile->tick() - $before)->toBe($plan['ticks'])
        ->and($profile->currentSea())->toBe(StaticSeaGraph::STORM_OCEAN)
        ->and($profile->position())->toBe($target);
});
