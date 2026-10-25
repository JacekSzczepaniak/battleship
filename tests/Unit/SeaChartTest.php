<?php

declare(strict_types=1);

use App\Domain\Expedition\BlackPearlIslandCatalog;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ChartCondition;
use App\Domain\Expedition\GateBlock;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\MistSeaIslandCatalog;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaChartOffer;
use App\Domain\Expedition\SeaChartSource;
use App\Domain\Expedition\SeaEdge;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;

// Mapa akwenu i brama „wody nieznane” do Wód Czarnej Perły (#143, Epic J D5).

const PEARL = StaticSeaGraph::BLACK_PEARL;
const UNCHARTED = StaticSeaGraph::UNCHARTED_WATERS;
const CHART_SEED = 4343;

function chartGraph(): StaticSeaGraph
{
    return new StaticSeaGraph(new StaticIslandCatalog());
}

/** Mapa akwenu gry ze znajomością pozostałych akwenów — jak z WorldFactory. */
function chartMap(string $seaId): WorldMap
{
    $graph = chartGraph();
    $maps = [];
    foreach ($graph->seas() as $sea) {
        $maps[$sea->id] = $sea->map(CHART_SEED, $graph->passagesFrom($sea->id));
    }

    return $maps[$seaId]->withOtherSeas(...array_values($maps));
}

/**
 * Admirał na zadanym sektorze akwenu (domyślnie na bramie wód nieznanych).
 *
 * @param array{x:int,y:int}|null $position
 * @param array<string, int>      $charts
 * @param list<string>            $owned
 */
function chartCaptain(
    string $sea = StaticSeaGraph::MIST_SEA,
    array $charts = [],
    ?Resources $cargo = null,
    array $stockpiles = [],
    ?array $position = null,
    int $tick = 0,
    array $owned = [],
): CaptainProfile {
    $position ??= chartMap($sea)->gatePosition(UNCHARTED);

    return CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 1400, [], null, $cargo ?? Resources::none(), CHART_SEED,
        $position, ["{$position['x']}:{$position['y']}"],
        tick: $tick,
        stockpiles: $stockpiles,
        depositTicks: array_fill_keys($owned, 0),
        ownedIslands: array_fill_keys($owned, 0),
        counterattacks: ['pending' => null, 'calmFrom' => 0, 'rolledTo' => 0, 'last' => null],
        sea: $sea,
        seaCharts: $charts,
    );
}

/** Admirał w porcie handlowym akwenu, z ładownią i magazynem portu. */
function chartBuyer(string $sea = StaticSeaGraph::MIST_SEA, ?Resources $cargo = null, ?Resources $stock = null): CaptainProfile
{
    $port = chartGraph()->sea($sea)->tradePort;

    return chartCaptain(
        $sea,
        cargo: $cargo,
        stockpiles: null !== $stock ? [$port->id => $stock] : [],
        position: chartMap($sea)->tradePortPosition(),
    );
}

it('za wodami nieznanymi leżą Wody Czarnej Perły: motyw 30% / 1 / ×3, 3 wyspy admirała, bez portu', function () {
    $pearl = chartGraph()->sea(PEARL);
    $mistXp = array_map(static fn (Island $i) => $i->xpWin, (new MistSeaIslandCatalog())->all());

    expect($pearl->name)->toBe('Wody Czarnej Perły')
        ->and($pearl->theme->toArray())->toBe(['stormChance' => 30, 'visibility' => 1, 'depositMultiplier' => 3])
        ->and($pearl->tradePort)->toBeNull()
        ->and($pearl->enemyVariants)->toBe([ShipVariant::KuterTorpedowy, ShipVariant::CiezkiNiszczyciel, ShipVariant::LotniskowiecEskortowy])
        ->and(array_map(static fn (Island $i) => [$i->id, $i->requiredRank, $i->shipyardLevel, $i->depositYield->toArray(), $i->stronghold], $pearl->islands->all()))->toBe([
            ['przystan-kaperska', Rank::Admiral, 3, ['wood' => 0, 'steel' => 0, 'gold' => 0], false],
            ['rafa-perlowa', Rank::Admiral, 0, ['wood' => 0, 'steel' => 0, 'gold' => 2], false],
            ['wrak-czarnej-perly', Rank::Admiral, 0, ['wood' => 0, 'steel' => 0, 'gold' => 0], true],
        ])
        // treść poza końcówką gry: każda wyspa daje więcej XP niż najlepsza na Morzu Mgieł
        ->and(min(array_map(static fn (Island $i) => $i->xpWin, $pearl->islands->all())))->toBeGreaterThan(max($mistXp));
});

it('wody nieznane łączą wschodnią krawędź Morza Mgieł z zachodnią krawędzią akwenu 4, a otwiera je mapa', function () {
    $graph = chartGraph();
    $there = $graph->passage(StaticSeaGraph::MIST_SEA, UNCHARTED);
    $back = $graph->passage(PEARL, UNCHARTED);

    expect($there->to)->toBe(PEARL)
        ->and($there->edge)->toBe(SeaEdge::East)
        ->and($back->to)->toBe(StaticSeaGraph::MIST_SEA)
        ->and($back->edge)->toBe(SeaEdge::West)
        ->and($there->gate)->toBe($back->gate)
        ->and($there->gate->name)->toBe('Wody nieznane')
        ->and($there->gate->guardian())->toBeNull()
        ->and($there->gate->condition)->toEqual(new ChartCondition(PEARL))
        ->and(chartMap(StaticSeaGraph::MIST_SEA)->gatePosition(UNCHARTED)['x'])->toBe(WorldMap::WIDTH - 1)
        ->and(chartMap(PEARL)->gatePosition(UNCHARTED)['x'])->toBe(0)
        // otwarty ocean dalej na zachodzie Mgieł
        ->and(chartMap(StaticSeaGraph::MIST_SEA)->gatePosition(StaticSeaGraph::OPEN_OCEAN)['x'])->toBe(0);
});

it('bez mapy przejście przez wody nieznane jest odrzucane w obie strony', function (string $from, string $to) {
    $profile = chartCaptain($from);
    $passage = chartGraph()->passage($from, UNCHARTED);

    expect($profile->gateBlocker($passage))->toBe(GateBlock::Chart)
        ->and(fn () => $profile->passGate($passage, chartMap($from), chartMap($to)))
        ->toThrow(DomainException::class, 'Gate locked: requires a sea chart')
        ->and($profile->tick())->toBe(0);
})->with([
    'Mgły → Czarna Perła' => [StaticSeaGraph::MIST_SEA, PEARL],
    'Czarna Perła → Mgły' => [PEARL, StaticSeaGraph::MIST_SEA],
]);

it('z mapą flota przepływa na Wody Czarnej Perły i z powrotem, po 1 tiku, a mapa zostaje', function () {
    $profile = chartCaptain(charts: [PEARL => 3], tick: 5);
    $graph = chartGraph();
    $mist = chartMap(StaticSeaGraph::MIST_SEA);
    $pearl = chartMap(PEARL);

    $profile->passGate($graph->passage(StaticSeaGraph::MIST_SEA, UNCHARTED), $mist, $pearl);

    // widoczność 1: odkryte sąsiedztwo bramy, nie tylko jej sektor
    expect($profile->currentSea())->toBe(PEARL)
        ->and($profile->tick())->toBe(6)
        ->and($profile->position())->toBe($pearl->gatePosition(UNCHARTED))
        ->and(count($profile->discoveredSectors()))->toBeGreaterThan(1);

    $profile->passGate($graph->passage(PEARL, UNCHARTED), $pearl, $mist);

    expect($profile->currentSea())->toBe(StaticSeaGraph::MIST_SEA)
        ->and($profile->tick())->toBe(7)
        ->and($profile->position())->toBe($mist->gatePosition(UNCHARTED))
        ->and($profile->seaCharts())->toBe([PEARL => 3]);
});

it('mapę sprzedaje tylko Bazar we Mgle, za 150 złota', function () {
    $offers = [];
    foreach (chartGraph()->seas() as $sea) {
        if (null !== $sea->tradePort?->chart) {
            $offers[$sea->tradePort->id] = [$sea->tradePort->chart->seaId, $sea->tradePort->chart->price];
        }
    }

    expect($offers)->toBe(['bazar-we-mgle' => [PEARL, 150]]);
});

it('kupno mapy płaci złotem najpierw z magazynu portu, resztę z ładowni, za 1 tik, i zapisuje mapę w profilu', function () {
    // ładownia floty startowej mieści 15 sztuk, więc złoto na mapę leży głównie w magazynie
    $profile = chartBuyer(cargo: new Resources(wood: 5, gold: 10), stock: new Resources(gold: 140));
    $port = chartGraph()->sea(StaticSeaGraph::MIST_SEA)->tradePort;

    $gold = $profile->buySeaChart($port);

    $entry = $profile->log()[array_key_last($profile->log())];
    expect($gold)->toBe(150)
        ->and($profile->stockpile('bazar-we-mgle')->toArray())->toBe(['wood' => 0, 'steel' => 0, 'gold' => 0])
        ->and($profile->cargo()->toArray())->toBe(['wood' => 5, 'steel' => 0, 'gold' => 0])
        ->and($profile->tick())->toBe(1)
        ->and($profile->hasSeaChart(PEARL))->toBeTrue()
        ->and($profile->seaCharts())->toBe([PEARL => 1])
        ->and($entry->event)->toBe(LogEvent::ChartAcquired)
        ->and($entry->data)->toBe(['sea' => PEARL, 'source' => 'market', 'island' => 'bazar-we-mgle', 'gold' => 150])
        ->and($profile->gateBlocker(chartGraph()->passage(StaticSeaGraph::MIST_SEA, UNCHARTED)))->toBeNull();
});

it('zakup mapy to tik jak każda akcja: kuter remontowy naprawia na nim jeden statek (#152)', function () {
    $broken = OwnedShip::build(ShipType::Kuter);
    $broken->markDamaged();
    $port = chartMap(StaticSeaGraph::MIST_SEA)->tradePortPosition();
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 1400, [],
        [$broken, OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy), OwnedShip::build(ShipType::Transportowiec)],
        new Resources(wood: 10, steel: 10), CHART_SEED, $port, ["{$port['x']}:{$port['y']}"],
        stockpiles: ['bazar-we-mgle' => new Resources(gold: 150)],
        researched: [Technology::DokRemontowy, Technology::KuterRemontowy],
        sea: StaticSeaGraph::MIST_SEA,
    );
    $cost = $profile->repairCost(ShipType::Kuter);

    $profile->buySeaChart(chartGraph()->sea(StaticSeaGraph::MIST_SEA)->tradePort);

    expect($profile->tick())->toBe(1)
        ->and(array_filter($profile->fleet(), static fn (OwnedShip $s) => $s->isDamaged()))->toBe([])
        ->and($profile->cargo()->toArray())->toBe((new Resources(wood: 10, steel: 10))->subtract($cost)->toArray())
        ->and(array_map(static fn ($e) => $e->event, $profile->log()))->toContain(LogEvent::FieldRepair, LogEvent::ChartAcquired);
});

it('kupno mapy bez 150 złota albo drugi raz jest odrzucane bez zmian w profilu', function () {
    $port = chartGraph()->sea(StaticSeaGraph::MIST_SEA)->tradePort;
    $poor = chartBuyer(cargo: new Resources(gold: 9), stock: new Resources(wood: 300, gold: 140));

    expect(fn () => $poor->buySeaChart($port))->toThrow(DomainException::class, 'Not enough resources')
        ->and($poor->hasSeaChart(PEARL))->toBeFalse()
        ->and($poor->cargo()->gold)->toBe(9)
        ->and($poor->stockpile('bazar-we-mgle')->gold)->toBe(140)
        ->and($poor->tick())->toBe(0);

    $rich = chartBuyer(stock: new Resources(gold: 400));
    $rich->buySeaChart($port);

    expect(fn () => $rich->buySeaChart($port))->toThrow(DomainException::class, 'Sea chart already owned')
        ->and($rich->stockpile('bazar-we-mgle')->gold)->toBe(250)
        ->and($rich->tick())->toBe(1);
});

it('port bez mapy w ofercie odrzuca kupno', function () {
    $profile = chartBuyer(Sea::START, stock: new Resources(gold: 200));

    expect(fn () => $profile->buySeaChart(chartGraph()->sea(Sea::START)->tradePort))
        ->toThrow(DomainException::class, 'No sea chart for sale here');
});

it('cena mapy idzie za mnożnikiem ceny rynku, z zaokrągleniem w górę', function () {
    $offer = new SeaChartOffer(PEARL, 150);

    expect($offer->priceFor())->toBe(150)
        ->and($offer->priceFor(90))->toBe(135)
        ->and($offer->priceFor(125))->toBe(188)
        ->and(chartBuyer()->chartPrice($offer))->toBe(150);
});

it('ekspedycja (#144) przyznaje mapę bez tiku; druga mapa tego samego akwenu nic nie zmienia', function () {
    $profile = chartCaptain(tick: 9);

    expect($profile->grantSeaChart(PEARL, SeaChartSource::Expedition))->toBeTrue()
        ->and($profile->seaCharts())->toBe([PEARL => 9])
        ->and($profile->tick())->toBe(9)
        ->and($profile->log()[array_key_last($profile->log())]->data)->toBe(['sea' => PEARL, 'source' => 'expedition']);

    $logSize = count($profile->log());
    expect($profile->grantSeaChart(PEARL, SeaChartSource::Expedition))->toBeFalse()
        ->and($profile->log())->toHaveCount($logSize)
        ->and($profile->seaCharts())->toBe([PEARL => 9]);
});

it('mapy nie ma w łupach z bitew: wygrana na wyspie akwenu 4 nie daje mapy', function () {
    $island = (new BlackPearlIslandCatalog())->byId('rafa-perlowa');
    $profile = chartCaptain(PEARL, charts: [], position: chartMap(PEARL)->islandPosition($island->id));
    $gameId = GameId::new();
    $profile->startBattle($island->id, $gameId);

    $profile->settleBattle($gameId, 'won', $island->xpWin, $island->lootWin);

    expect($profile->ownsIsland('rafa-perlowa'))->toBeTrue()
        ->and($profile->seaCharts())->toBe([]);
});

it('złoża Wód Czarnej Perły dają ×3', function () {
    $pearl = chartGraph()->sea(PEARL);

    expect(chartCaptain(PEARL)->depositYield($pearl->islands->byId('rafa-perlowa'), $pearl->theme)->toArray())
        ->toBe(['wood' => 0, 'steel' => 0, 'gold' => 6]);
});

it('kontratak trafia w wyspę akwenu 4, gdy flota stoi na Morzu Mgieł', function () {
    $mist = chartMap(StaticSeaGraph::MIST_SEA);
    $profile = chartCaptain(
        position: $mist->islandPosition('kotwicowisko-mgiel'),
        tick: 200,
        owned: ['rafa-perlowa'],
    );

    $profile->ensureWorldState($mist);

    expect($profile->lastCounterattack()['island'] ?? null)->toBe('rafa-perlowa')
        ->and($profile->lastCounterattack()['absent'])->toBeTrue()
        ->and($profile->ownsIsland('rafa-perlowa'))->toBeFalse();
});
