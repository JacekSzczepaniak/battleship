<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\InlandBlock;
use App\Domain\Expedition\InlandExpedition;
use App\Domain\Expedition\InlandTreasure;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\ResearchBlock;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaChartSource;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\TechUnlock;
use App\Domain\Expedition\WildIsland;
use App\Domain\Expedition\WorldMap;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// Ekspedycje w głąb dzikich wysp i artefakty jako questowa ścieżka drzewka (#144, Epic J D6–D7).

const INLAND_SEED = 4444;

function inlandGraph(): StaticSeaGraph
{
    return new StaticSeaGraph(new StaticIslandCatalog());
}

/** Mapa akwenu gry ze znajomością pozostałych akwenów — jak z WorldFactory. */
function inlandMap(string $seaId = Sea::START): WorldMap
{
    $graph = inlandGraph();
    $maps = [];
    foreach ($graph->seas() as $sea) {
        $maps[$sea->id] = $sea->map(INLAND_SEED, $graph->passagesFrom($sea->id));
    }

    return $maps[$seaId]->withOtherSeas(...array_values($maps));
}

function wildIslandOf(string $seaId = Sea::START): WildIsland
{
    return inlandGraph()->sea($seaId)->wildIsland ?? throw new LogicException('Sea without wild island');
}

/**
 * Admirał na sektorze dzikiej wyspy akwenu (kuter i niszczyciel, ładownia 35).
 *
 * @param list<ShipType>|null $fleet      typy statków; budowane od nowa przy każdym wywołaniu
 * @param list<Technology>    $researched
 * @param array<string, int>  $charts
 * @param array<string, int>  $wildVisits
 */
function explorer(
    string $sea = Sea::START,
    int $tick = 0,
    int $xp = 1300,
    ?array $fleet = null,
    bool $damaged = false,
    ?Resources $cargo = null,
    array $researched = [],
    array $charts = [],
    array $wildVisits = [],
    ?array $research = null,
): CaptainProfile {
    $map = inlandMap($sea);
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', $xp, [],
        array_map(static function (ShipType $type) use ($damaged) {
            $ship = OwnedShip::build($type);
            if ($damaged) {
                $ship->markDamaged();
            }

            return $ship;
        }, $fleet ?? [ShipType::Kuter, ShipType::Niszczyciel]),
        $cargo ?? Resources::none(),
        INLAND_SEED, $map->wildIslandPosition(),
        tick: $tick,
        researched: $researched,
        research: $research,
        sea: $sea,
        seaCharts: $charts,
        wildVisits: $wildVisits,
    );
    $profile->ensureWorldState($map);

    return $profile;
}

/**
 * Pierwszy tik startu, przy którym przebieg ekspedycji spełnia warunek
 * (rzuty idą z seeda i tiku, więc wystarczy przeszukać tiki).
 *
 * @param callable(CaptainProfile, WildIsland, WorldMap): bool $run
 */
function inlandTickWhere(callable $run, string $sea = Sea::START, array $options = []): int
{
    for ($tick = 0; $tick < 3000; ++$tick) {
        if ($run(explorer($sea, $tick, ...$options), wildIslandOf($sea), inlandMap($sea))) {
            return $tick;
        }
    }

    throw new LogicException('No tick with this outcome');
}

/** Pełna ekspedycja: głębiej, dopóki się da. */
function inlandAllTheWay(CaptainProfile $profile, WildIsland $island, WorldMap $map): array
{
    $outcome = $profile->startInlandExpedition($island, $map);
    while ('passed' === $outcome['result']) {
        $outcome = $profile->goDeeperInland($island, $map);
    }

    return $outcome;
}

function inlandLog(CaptainProfile $profile, LogEvent $event): array
{
    return array_values(array_map(
        static fn ($e) => $e->data,
        array_filter($profile->log(), static fn ($e) => $e->event === $event),
    ));
}

it('każdy akwen ma jedną dziką wyspę bez areny i bandery: poza trasą wysp, ranga wejścia na akwen', function () {
    $graph = inlandGraph();
    $wild = array_map(static fn (Sea $sea) => [$sea->wildIsland?->id, $sea->wildIsland?->requiredRank], $graph->seas());

    expect($wild)->toBe([
        ['wyspa-papug', Rank::Majtek],
        ['wyspa-dymiacej-gory', Rank::Porucznik],
        ['wyspa-zaginionych', Rank::Komandor],
        ['wyspa-czaszki', Rank::Admiral],
    ]);
    foreach ($graph->seas() as $sea) {
        expect($sea->islands->byId($sea->wildIsland->id))->toBeNull()
            ->and($graph->wildIsland($sea->wildIsland->id))->toBe($sea->wildIsland);
    }
    expect([WildIsland::risk(1), WildIsland::risk(2), WildIsland::risk(3)])->toBe([20, 35, 50])
        ->and(wildIslandOf()->reward(3)->toArray())->toBe(['wood' => 0, 'steel' => 6, 'gold' => 5])
        ->and(wildIslandOf(StaticSeaGraph::MIST_SEA)->treasures)->toBe([[InlandTreasure::Chart, 35], [InlandTreasure::Artifact, 30]])
        ->and(wildIslandOf(StaticSeaGraph::BLACK_PEARL)->treasures)->toBe([[InlandTreasure::Legend, 30], [InlandTreasure::Artifact, 30]]);
});

it('sektor dzikiej wyspy to osobny rzut: wyspy, bramy i port stoją tam, gdzie stały', function () {
    foreach (inlandGraph()->seas() as $sea) {
        $with = inlandMap($sea->id);
        $gates = [];
        foreach (inlandGraph()->passagesFrom($sea->id) as $passage) {
            $gates[$passage->gate->id] = $passage->edge;
        }
        // ta sama mapa bez dzikiej wyspy
        $without = WorldMap::generate($sea->seed(INLAND_SEED), array_keys($with->islands()), $sea->islands->all(), $sea->theme, $sea->id, $gates, $sea->tradePort?->id);
        $wild = $with->wildIslandPosition();

        expect($with->islands())->toBe($without->islands())
            ->and($with->gates())->toBe($without->gates())
            ->and($with->tradePortPosition())->toBe($without->tradePortPosition())
            ->and($wild)->not->toBeNull()
            ->and($with->islandAt($wild['x'], $wild['y']))->toBeNull()
            ->and($with->gateAt($wild['x'], $wild['y']))->toBeNull()
            ->and($wild)->not->toBe($with->tradePortPosition());
    }
});

it('etap i ryzyko są powtarzalne dla seeda i tiku', function () {
    $island = wildIslandOf();
    $first = explorer(tick: 7);
    $second = explorer(tick: 7);

    expect(inlandAllTheWay($first, $island, inlandMap()))->toEqual(inlandAllTheWay($second, $island, inlandMap()))
        ->and(array_map(static fn (OwnedShip $s) => $s->isDamaged(), $first->fleet()))->toBe(array_map(static fn (OwnedShip $s) => $s->isDamaged(), $second->fleet()))
        ->and($first->cargo())->toEqual($second->cargo())
        ->and($first->tick())->toBe($second->tick());
});

it('udany etap kosztuje tik i zbiera nagrodę, a „wracaj” wnosi ją do ładowni bez tiku', function () {
    $tick = inlandTickWhere(static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'passed' === $p->startInlandExpedition($i, $m)['result']
        && 'passed' === $p->goDeeperInland($i, $m)['result']);
    $profile = explorer(tick: $tick);
    $island = wildIslandOf();

    $one = $profile->startInlandExpedition($island, inlandMap());
    $two = $profile->goDeeperInland($island, inlandMap());

    expect($one['stage'])->toBe(1)
        ->and($two['collected']->toArray())->toBe(['wood' => 14, 'steel' => 6, 'gold' => 0])
        ->and($profile->tick())->toBe($tick + 2)
        ->and($profile->inlandExpedition()?->stage)->toBe(2)
        ->and($profile->cargo()->isZero())->toBeTrue();

    $back = $profile->returnFromInland($island);

    expect($back['received']->toArray())->toBe(['wood' => 14, 'steel' => 6, 'gold' => 0])
        ->and($profile->cargo()->toArray())->toBe(['wood' => 14, 'steel' => 6, 'gold' => 0])
        ->and($profile->tick())->toBe($tick + 2)
        ->and($profile->inlandExpedition())->toBeNull()
        ->and(inlandLog($profile, LogEvent::InlandReturned))->toBe([['island' => 'wyspa-papug', 'stage' => 2, 'received' => ['wood' => 14, 'steel' => 6, 'gold' => 0]]]);
});

it('porażka zabiera zebrane nagrody i uszkadza jeden statek floty', function () {
    $tick = inlandTickWhere(static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'passed' === $p->startInlandExpedition($i, $m)['result']
        && 'failed' === $p->goDeeperInland($i, $m)['result'], options: ['cargo' => new Resources(wood: 3)]);
    $profile = explorer(tick: $tick, cargo: new Resources(wood: 3));
    $island = wildIslandOf();

    $profile->startInlandExpedition($island, inlandMap());
    $failed = $profile->goDeeperInland($island, inlandMap());
    $damaged = array_values(array_filter($profile->fleet(), static fn (OwnedShip $s) => $s->isDamaged()));

    expect($failed['lost']->toArray())->toBe(['wood' => 8, 'steel' => 0, 'gold' => 0])
        ->and($failed['damagedShip'])->toBe($damaged[0]->type->value)
        ->and($damaged)->toHaveCount(1)
        // ładunek sprzed ekspedycji zostaje, przepada tylko zebrane
        ->and($profile->cargo()->toArray())->toBe(['wood' => 3, 'steel' => 0, 'gold' => 0])
        ->and($profile->inlandExpedition())->toBeNull()
        ->and(inlandLog($profile, LogEvent::InlandFailed))->toBe([['island' => 'wyspa-papug', 'stage' => 2, 'lost' => ['wood' => 8, 'steel' => 0, 'gold' => 0], 'ship' => $damaged[0]->type->value]]);
});

it('wyspa odpoczywa 15 tików po ekspedycji', function () {
    $island = wildIslandOf();
    $resting = explorer(tick: 20, wildVisits: [$island->id => 10]);
    $rested = explorer(tick: 25, wildVisits: [$island->id => 10]);

    expect($resting->inlandBlocker($island, inlandMap()))->toBe(InlandBlock::Resting)
        ->and($resting->wildIslandReadyAt($island->id))->toBe(25)
        ->and($rested->inlandBlocker($island, inlandMap()))->toBeNull()
        ->and(fn () => $resting->startInlandExpedition($island, inlandMap()))->toThrow(DomainException::class, 'Wild island needs rest');
});

it('blokady startu: obecność, ranga, sprawna flota; w trakcie ekspedycji flota nie odpływa', function () {
    $island = wildIslandOf();
    $away = explorer();
    $pos = $away->position();
    $away->sail($pos['x'] > 0 ? $pos['x'] - 1 : 1, $pos['y'], inlandMap());

    expect(explorer(xp: 0)->inlandBlocker($island, inlandMap()))->toBe(InlandBlock::Rank)
        ->and(fn () => explorer(xp: 0)->startInlandExpedition($island, inlandMap()))->toThrow(DomainException::class, 'Wild island locked: requires rank majtek')
        ->and(explorer(damaged: true)->inlandBlocker($island, inlandMap()))->toBe(InlandBlock::NoSeaworthyShips)
        ->and($away->inlandBlocker($island, inlandMap()))->toBe(InlandBlock::NotPresent)
        // wyspa innego akwenu też jest „nieobecna”
        ->and(explorer()->inlandBlocker(wildIslandOf(StaticSeaGraph::STORM_OCEAN), inlandMap()))->toBe(InlandBlock::NotPresent);

    $tick = inlandTickWhere(static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'passed' === $p->startInlandExpedition($i, $m)['result']);
    $busy = explorer(tick: $tick);
    $busy->startInlandExpedition($island, inlandMap());

    expect($busy->inlandBlocker($island, inlandMap()))->toBe(InlandBlock::InProgress)
        ->and(fn () => $busy->sail($pos['x'] > 0 ? $pos['x'] - 1 : 1, $pos['y'], inlandMap()))->toThrow(DomainException::class, 'Inland expedition in progress')
        ->and(fn () => explorer()->returnFromInland($island))->toThrow(DomainException::class, 'No inland expedition');
});

it('po etapie 3 ekspedycja wraca sama, a nagrody ponad ładownię przepadają', function () {
    $options = ['fleet' => [ShipType::Kuter]];
    $tick = inlandTickWhere(static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'completed' === inlandAllTheWay($p, $i, $m)['result'], options: $options);
    $profile = explorer(Sea::START, $tick, ...$options);

    $done = inlandAllTheWay($profile, wildIslandOf(), inlandMap());
    $capacity = $profile->cargoCapacity();

    expect($done['stage'])->toBe(3)
        ->and($done['collected']->toArray())->toBe(['wood' => 14, 'steel' => 12, 'gold' => 5])
        ->and($profile->cargo()->total())->toBe(min(31 + ($done['treasure']['gold'] ?? 0), $capacity))
        ->and($done['received']->add($done['lost'])->total())->toBe(31 + ($done['treasure']['gold'] ?? 0))
        ->and($profile->inlandExpedition())->toBeNull()
        ->and($profile->tick())->toBe($tick + 3);
});

it('artefakt: technologia działa od razu, drzewko zna ścieżkę questową', function () {
    $tick = inlandTickWhere(static function (CaptainProfile $p, WildIsland $i, WorldMap $m) {
        $treasure = inlandAllTheWay($p, $i, $m)['treasure'];

        return isset($treasure['technology']);
    });
    $profile = explorer(tick: $tick);

    $treasure = inlandAllTheWay($profile, wildIslandOf(), inlandMap())['treasure'];
    $technology = Technology::from($treasure['technology']);

    expect($treasure['type'])->toBe('artifact')
        ->and($technology->isArtifactCandidate())->toBeTrue()
        ->and($technology->unlock())->toBe(TechUnlock::Academy)
        ->and($profile->hasResearched($technology))->toBeTrue()
        ->and($profile->hasArtifact($technology))->toBeTrue()
        ->and($profile->researchBlocker($technology, null, inlandMap()))->toBe(ResearchBlock::Researched)
        ->and(inlandLog($profile, LogEvent::ArtifactFound))->toBe([['technology' => $technology->value, 'island' => 'wyspa-papug']]);
});

it('kandydaci na artefakt: poziomy 1–2, bez zbadanych i bez badania w toku; gdy brak, skarb to złoto', function () {
    $levelsOneTwo = array_values(array_filter(Technology::cases(), static fn (Technology $t) => $t->isArtifactCandidate()));
    $busy = explorer(researched: [Technology::Spichlerze], research: ['technology' => Technology::MapySztormowe, 'island' => 'zatoka-rozbitka', 'progress' => 0, 'countedTo' => 0]);

    expect(array_map(static fn (Technology $t) => $t->value, $levelsOneTwo))->toBe([
        'spichlerze', 'mapy-sztormowe', 'sonar-dalekiego-zasiegu', 'zegluga-dwusektorowa',
        'pojemne-ladownie', 'dok-remontowy', 'lepsze-narzedzia', 'niszczyciel-zwiadowczy', 'nawigator',
    ])
        ->and($busy->artifactCandidates())->not->toContain(Technology::Spichlerze)
        ->and($busy->artifactCandidates())->not->toContain(Technology::MapySztormowe)
        ->and($busy->artifactCandidates())->toHaveCount(7);

    $options = ['researched' => $levelsOneTwo];
    $tick = inlandTickWhere(static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'artifact' === (inlandAllTheWay($p, $i, $m)['treasure']['type'] ?? null), options: $options);
    $veteran = explorer(Sea::START, $tick, ...$options);

    expect(inlandAllTheWay($veteran, wildIslandOf(), inlandMap())['treasure'])->toBe(['type' => 'artifact', 'gold' => WildIsland::TREASURE_FALLBACK_GOLD])
        ->and($veteran->artifacts())->toBe([]);
});

it('mapa Wód Czarnej Perły z ekspedycji na Wyspie Zaginionych; posiadana zamienia się w złoto', function () {
    $mist = StaticSeaGraph::MIST_SEA;
    $chart = static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'chart' === (inlandAllTheWay($p, $i, $m)['treasure']['type'] ?? null);
    $tick = inlandTickWhere($chart, $mist);
    $profile = explorer($mist, $tick);

    expect(inlandAllTheWay($profile, wildIslandOf($mist), inlandMap($mist))['treasure'])->toBe(['type' => 'chart', 'sea' => StaticSeaGraph::BLACK_PEARL])
        ->and($profile->hasSeaChart(StaticSeaGraph::BLACK_PEARL))->toBeTrue()
        ->and(inlandLog($profile, LogEvent::ChartAcquired))->toBe([['sea' => StaticSeaGraph::BLACK_PEARL, 'source' => SeaChartSource::Expedition->value, 'island' => 'wyspa-zaginionych']]);

    $owner = explorer($mist, $tick, charts: [StaticSeaGraph::BLACK_PEARL => 1]);
    $gold = $owner->cargo()->gold;
    $done = inlandAllTheWay($owner, wildIslandOf($mist), inlandMap($mist));

    expect($done['treasure'])->toBe(['type' => 'chart', 'gold' => 20])
        ->and($owner->cargo()->gold - $gold)->toBe(14 + 20);
});

it('legenda „Czarna perła” tylko z questu: +1 do widoczności na wszystkich akwenach', function () {
    $pearl = StaticSeaGraph::BLACK_PEARL;
    $tick = inlandTickWhere(static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'legend' === (inlandAllTheWay($p, $i, $m)['treasure']['type'] ?? null), $pearl);
    $profile = explorer($pearl, $tick);

    expect(Technology::CzarnaPerla->unlock())->toBe(TechUnlock::Quest)
        ->and(explorer()->researchBlocker(Technology::CzarnaPerla, null, inlandMap()))->toBe(ResearchBlock::QuestOnly);

    $treasure = inlandAllTheWay($profile, wildIslandOf($pearl), inlandMap($pearl))['treasure'];
    $mistTheme = inlandGraph()->sea(StaticSeaGraph::MIST_SEA)->theme;

    expect($treasure)->toBe(['type' => 'legend', 'technology' => 'czarna-perla'])
        ->and($profile->hasResearched(Technology::CzarnaPerla))->toBeTrue()
        ->and($profile->hasArtifact(Technology::CzarnaPerla))->toBeTrue()
        ->and($profile->visibility($mistTheme))->toBe(1)
        ->and(explorer()->visibility($mistTheme))->toBe(0)
        ->and($profile->visibility(inlandGraph()->sea($pearl)->theme))->toBe(2);
});

it('z Czarną perłą rejs po Morzu Mgieł odkrywa sąsiedztwo zamiast samego sektora', function () {
    $mist = StaticSeaGraph::MIST_SEA;
    $plain = explorer($mist);
    $legend = explorer($mist, researched: [Technology::CzarnaPerla]);
    $pos = $plain->position();
    $to = [$pos['x'] > 0 ? $pos['x'] - 1 : 1, $pos['y']];

    $plain->sail($to[0], $to[1], inlandMap($mist));
    $legend->sail($to[0], $to[1], inlandMap($mist));

    expect(count($legend->discoveredSectors()))->toBeGreaterThan(count($plain->discoveredSectors()) + 2);
});

it('snapshot zachowuje ekspedycję w toku, odpoczynek wysp i technologie z questu', function () {
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 0, [],
        researched: [Technology::Spichlerze, Technology::CzarnaPerla],
        inland: new InlandExpedition('wyspa-papug', 2, new Resources(wood: 14, steel: 6), 9),
        wildVisits: ['wyspa-czaszki' => 4],
        // technologia spoza zbadanych nie jest artefaktem
        artifacts: ['czarna-perla' => 7, 'dok-remontowy' => 3],
    );
    $mapper = new ProfileSnapshotMapper();
    $back = $mapper->toDomain((string) $profile->id(), $mapper->toArray($profile));

    expect($back->inlandExpedition()?->toArray())->toBe(['island' => 'wyspa-papug', 'stage' => 2, 'collected' => ['wood' => 14, 'steel' => 6, 'gold' => 0], 'startedAt' => 9])
        ->and($back->wildVisits())->toBe(['wyspa-czaszki' => 4])
        ->and($back->artifacts())->toBe(['czarna-perla' => 7])
        ->and($mapper->toDomain((string) $profile->id(), ['name' => 'Jacek'])->inlandExpedition())->toBeNull();
});
