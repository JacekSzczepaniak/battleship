<?php

declare(strict_types=1);

use App\Domain\Expedition\BattleKind;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\GateBlock;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaEdge;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// Strzeżona cieśnina i Ocean Sztormów (#107): brama ze strażnikiem, przejście między akwenami.

const OCEAN = StaticSeaGraph::STORM_OCEAN;
const STRAIT = StaticSeaGraph::GUARDED_STRAIT;

function straitGraph(): StaticSeaGraph
{
    return new StaticSeaGraph(new StaticIslandCatalog());
}

/** Mapa akwenu gry z sektorami jego bram. */
function seaMap(string $seaId, int $worldSeed): WorldMap
{
    $graph = straitGraph();

    return $graph->sea($seaId)->map($worldSeed, $graph->passagesFrom($seaId));
}

/**
 * Kapitan na danym akwenie, domyślnie na sektorze strzeżonej cieśniny.
 *
 * @param array{x:int,y:int}|null $position    null = sektor wyspy $atIsland albo bramy
 * @param list<string>|null       $discovered  odkryte sektory akwenu floty
 * @param array<string, int>      $openedGates
 * @param list<OwnedShip>|null    $fleet
 * @param list<string>            $owned
 * @param list<Technology>        $researched
 */
function straitCaptain(
    int $xp = 400,
    string $sea = Sea::START,
    ?array $position = null,
    array $openedGates = [],
    ?array $fleet = null,
    ?Resources $cargo = null,
    int $tick = 0,
    array $owned = [],
    array $researched = [],
    ?array $counterattacks = null,
    array $stockpiles = [],
    ?string $atIsland = null,
    ?array $discovered = null,
): CaptainProfile {
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $map = seaMap($sea, $seed);

    return CaptainProfile::fromSnapshot(
        $id, 'Jacek', $xp, [], $fleet, $cargo ?? Resources::none(), $seed,
        $position ?? (null !== $atIsland ? $map->islandPosition($atIsland) : $map->gatePosition(STRAIT)),
        $discovered,
        tick: $tick,
        stockpiles: $stockpiles,
        depositTicks: array_fill_keys($owned, 0),
        ownedIslands: array_fill_keys($owned, 0),
        counterattacks: $counterattacks,
        researched: $researched,
        sea: $sea,
        openedGates: $openedGates,
    );
}

/** Rozgrywa bitwę ze strażnikiem z wynikiem i nagrodami strażnika cieśniny. */
function fightGuardian(CaptainProfile $profile, string $result, array $sunkLengths = []): array
{
    $guardian = straitGraph()->gate(STRAIT)->guardian();
    $gameId = GameId::new();
    $profile->startGuardianBattle(STRAIT, $gameId, array_map(static fn (OwnedShip $s) => $s->id, $profile->activeFleet()));

    return $profile->settleBattle(
        $gameId,
        $result,
        'won' === $result ? $guardian->xpWin : $guardian->xpLoss,
        'won' === $result ? $guardian->lootWin : $guardian->lootLoss,
        $sunkLengths,
    );
}

it('za strzeżoną cieśniną leży Ocean Sztormów: motyw 40% / 1 / ×2 i 5 wysp od porucznika do kapitana', function () {
    $graph = straitGraph();
    $ocean = $graph->sea(OCEAN);

    expect(array_map(static fn (Sea $s) => $s->id, $graph->seas()))->toBe([Sea::START, OCEAN])
        ->and($ocean->name)->toBe('Ocean Sztormów')
        ->and($ocean->theme->toArray())->toBe(['stormChance' => 40, 'visibility' => 1, 'depositMultiplier' => 2])
        ->and(array_map(static fn (Island $i) => [$i->id, $i->requiredRank, $i->shipyardLevel, $i->depositYield->toArray()], $ocean->islands->all()))->toBe([
            ['przystan-wichrow', Rank::Porucznik, 2, ['wood' => 0, 'steel' => 0, 'gold' => 0]],
            ['rafa-kotwiczna', Rank::Porucznik, 0, ['wood' => 0, 'steel' => 3, 'gold' => 0]],
            ['wyspa-latarnikow', Rank::Porucznik, 0, ['wood' => 3, 'steel' => 0, 'gold' => 0]],
            ['zatoka-burz', Rank::Kapitan, 0, ['wood' => 0, 'steel' => 2, 'gold' => 0]],
            ['bastion-fal', Rank::Kapitan, 0, ['wood' => 0, 'steel' => 0, 'gold' => 0]],
        ]);

    // łupy i XP wyższe niż na Twierdzy Admiralicji (120 XP; 20/22/18)
    $fortress = (new StaticIslandCatalog())->byId('twierdza-admiralicji');
    foreach ($ocean->islands->all() as $island) {
        expect($island->xpWin)->toBeGreaterThan($fortress->xpWin)
            ->and($island->lootWin->steel)->toBeGreaterThan($fortress->lootWin->steel)
            ->and($island->lootWin->gold)->toBeGreaterThanOrEqual($fortress->lootWin->gold);
    }

    // jedna brama, dwie krawędzie: wschód Morza Rozbitków ↔ zachód Oceanu
    $there = $graph->passage(Sea::START, STRAIT);
    $back = $graph->passage(OCEAN, STRAIT);
    expect([$there->to, $there->edge, $back->to, $back->edge])->toBe([OCEAN, SeaEdge::East, Sea::START, SeaEdge::West])
        ->and($there->gate)->toBe($back->gate)
        ->and($there->gate->name)->toBe('Strzeżona cieśnina');
});

it('strażnik cieśniny: od porucznika, plansza 12×12 fun, lotniskowiec + 2 niszczyciele + 2 kutry + 3 tratwy', function () {
    $guardian = straitGraph()->gate(STRAIT)->guardian();

    expect($guardian->requiredRank)->toBe(Rank::Porucznik)
        ->and([$guardian->mode, $guardian->boardWidth, $guardian->boardHeight])->toBe(['fun', 12, 12])
        ->and($guardian->composition())->toBe([4 => 1, 3 => 2, 2 => 2, 1 => 3])
        ->and($guardian->fleetCells())->toBe(17)
        ->and($guardian->squadCellLimit())->toBe(28)
        ->and([$guardian->xpWin, $guardian->xpLoss])->toBe([200, 50])
        ->and($guardian->lootWin->toArray())->toBe(['wood' => 40, 'steel' => 40, 'gold' => 20])
        ->and($guardian->lootLoss->toArray())->toBe(['wood' => 10, 'steel' => 0, 'gold' => 0]);
});

it('sektor bramy leży na krawędzi mapy poza wyspami, a rozkład wysp Morza Rozbitków się nie zmienia', function () {
    $graph = straitGraph();
    foreach ([1, 7, 2026, 123456789, 987654321] as $seed) {
        $rozbitkow = seaMap(Sea::START, $seed);
        $ocean = seaMap(OCEAN, $seed);
        $east = $rozbitkow->gatePosition(STRAIT);
        $west = $ocean->gatePosition(STRAIT);

        expect($east['x'])->toBe(WorldMap::WIDTH - 1)
            ->and($rozbitkow->islandAt($east['x'], $east['y']))->toBeNull()
            ->and($rozbitkow->gateAt($east['x'], $east['y']))->toBe(STRAIT)
            ->and($west['x'])->toBe(0)
            ->and($ocean->islandAt($west['x'], $west['y']))->toBeNull()
            ->and($rozbitkow->islands())->toBe($graph->start()->map($seed)->islands())
            ->and(seaMap(Sea::START, $seed)->gatePosition(STRAIT))->toBe($east);
    }
});

it('brama jest zamknięta poniżej porucznika przez rangę, od porucznika przez strażnika, a wygrana otwiera ją na stałe', function () {
    $passage = straitGraph()->passage(Sea::START, STRAIT);

    expect(straitCaptain(xp: 399)->gateBlocker($passage))->toBe(GateBlock::Rank);

    $profile = straitCaptain(xp: 400);
    expect($profile->gateBlocker($passage))->toBe(GateBlock::Guardian);

    $outcome = fightGuardian($profile, 'won');
    expect($outcome['gateOpened'])->toBeTrue()
        ->and($outcome['captured'])->toBeFalse()
        ->and($profile->isGateOpen(STRAIT))->toBeTrue()
        ->and($profile->gateBlocker($passage))->toBeNull()
        // otwarcie działa też z drugiej strony
        ->and($profile->gateBlocker(straitGraph()->passage(OCEAN, STRAIT)))->toBeNull()
        ->and(fn () => $profile->startGuardianBattle(STRAIT, GameId::new()))->toThrow(DomainException::class, 'Gate already open');
});

it('bitwa ze strażnikiem kosztuje tik i trafia do rejestru jako rodzaj guardian, a jej bilans liczy się pod id bramy', function () {
    $profile = straitCaptain();
    $gameId = GameId::new();
    $profile->startGuardianBattle(STRAIT, $gameId);

    expect($profile->tick())->toBe(1)
        ->and($profile->battleKindFor($gameId))->toBe(BattleKind::Guardian)
        ->and($profile->islandFor($gameId))->toBe(STRAIT);

    $profile->settleBattle($gameId, 'lost', 50);
    expect($profile->battleStats(STRAIT))->toBe(['wins' => 0, 'losses' => 1]);
});

it('wygrana ze strażnikiem daje 200 XP i łup do ładowni; nadmiar ponad pojemność przepada', function () {
    // kuter (15) + transportowiec (60) = 75 miejsca, łup ma 100 jednostek
    $profile = straitCaptain(fleet: [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Transportowiec)]);
    $outcome = fightGuardian($profile, 'won');

    expect($profile->xp())->toBe(600)
        ->and($profile->rank())->toBe(Rank::Kapitan)
        ->and($outcome['loot']->toArray())->toBe(['wood' => 40, 'steel' => 40, 'gold' => 20])
        ->and($profile->cargo()->total())->toBe(75)
        ->and($outcome['cargoLost']->total())->toBe(25)
        ->and($profile->cargo()->add($outcome['cargoLost'])->toArray())->toBe(['wood' => 40, 'steel' => 40, 'gold' => 20])
        ->and($profile->stockpiles())->toBe([])
        ->and($profile->log())->toEqual([new LogEntry(1, LogEvent::GateOpened, ['gate' => STRAIT])]);
});

it('przegrana ze strażnikiem działa jak każda: zatopione statki przepadają, brama zostaje zamknięta, próbę można powtórzyć', function () {
    $profile = straitCaptain(fleet: [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Niszczyciel)]);
    $outcome = fightGuardian($profile, 'lost', sunkLengths: [3]);

    expect($outcome['gateOpened'])->toBeFalse()
        ->and($outcome['lost'])->toBe(['niszczyciel'])
        ->and($profile->xp())->toBe(450)
        ->and($profile->cargo()->toArray())->toBe(['wood' => 10, 'steel' => 0, 'gold' => 0])
        ->and($profile->isGateOpen(STRAIT))->toBeFalse()
        ->and($profile->gateBlocker(straitGraph()->passage(Sea::START, STRAIT)))->toBe(GateBlock::Guardian);

    $profile->startGuardianBattle(STRAIT, GameId::new());
    expect($profile->tick())->toBe(2);
});

it('przejście odrzuca zamkniętą bramę i flotę z dala od sektora bramy', function () {
    $graph = straitGraph();
    $passage = $graph->passage(Sea::START, STRAIT);

    $guarded = straitCaptain();
    $seed = $guarded->worldSeed();
    expect(fn () => $guarded->passGate($passage, seaMap(Sea::START, $seed), seaMap(OCEAN, $seed)))
        ->toThrow(DomainException::class, 'Gate guarded: defeat the guardian first');

    $tooLow = straitCaptain(xp: 100);
    expect(fn () => $tooLow->passGate($passage, seaMap(Sea::START, $tooLow->worldSeed()), seaMap(OCEAN, $tooLow->worldSeed())))
        ->toThrow(DomainException::class, 'Gate locked: requires higher rank');

    $away = straitCaptain(position: ['x' => 0, 'y' => 0], openedGates: [STRAIT => 5]);
    expect(fn () => $away->passGate($passage, seaMap(Sea::START, $away->worldSeed()), seaMap(OCEAN, $away->worldSeed())))
        ->toThrow(DomainException::class, 'Not at gate')
        ->and($away->tick())->toBe(0)
        ->and($away->currentSea())->toBe(Sea::START);
});

it('przejście przez otwartą bramę kosztuje 1 tik, działa w obie strony i zachowuje stan opuszczonego akwenu', function () {
    $graph = straitGraph();
    $profile = straitCaptain(openedGates: [STRAIT => 3], tick: 10, discovered: ['0:0', '5:5']);
    $seed = $profile->worldSeed();
    $rozbitkow = seaMap(Sea::START, $seed);
    $ocean = seaMap(OCEAN, $seed);
    $east = $rozbitkow->gatePosition(STRAIT);
    $west = $ocean->gatePosition(STRAIT);

    $outcome = $profile->passGate($graph->passage(Sea::START, STRAIT), $rozbitkow, $ocean);

    expect($profile->currentSea())->toBe(OCEAN)
        ->and($profile->tick())->toBe(11)
        ->and($profile->position())->toBe($west)
        ->and($profile->isVisited($west['x'], $west['y']))->toBeTrue()
        // widoczność 1: sektor bramy i sąsiedztwo w granicach mapy (zachodnia krawędź)
        ->and($outcome['discoveredNow'])->toContain("{$west['x']}:{$west['y']}")
        ->and(count($outcome['discoveredNow']))->toBeIn([4, 6])
        ->and($outcome['cartography'])->toBeGreaterThanOrEqual(count($outcome['discoveredNow']));

    $profile->passGate($graph->passage(OCEAN, STRAIT), $ocean, $rozbitkow);

    expect($profile->currentSea())->toBe(Sea::START)
        ->and($profile->tick())->toBe(12)
        ->and($profile->position())->toBe($east)
        ->and($profile->discoveredSectors())->toContain('0:0', '5:5')
        ->and($profile->seaStates()[OCEAN]->isDiscovered(0, 0))->toBe(abs($west['y']) <= 1)
        ->and($profile->seaStates()[OCEAN]->position())->toBe($west);

    // dziennik (#111): oba przejścia, bez sztormów (wpis sztormu dochodzi tylko, gdy się zdarzył)
    $passages = array_values(array_filter($profile->log(), static fn (LogEntry $e) => LogEvent::GatePassed === $e->event));
    expect($passages)->toEqual([
        new LogEntry(11, LogEvent::GatePassed, ['gate' => STRAIT, 'from' => Sea::START, 'to' => OCEAN]),
        new LogEntry(12, LogEvent::GatePassed, ['gate' => STRAIT, 'from' => OCEAN, 'to' => Sea::START]),
    ]);
});

it('na Oceanie sztorm ma 40% (20% z Mapami sztormowymi), a złoża dają podwójny urobek', function () {
    $ocean = straitGraph()->sea(OCEAN);
    $rafa = $ocean->islands->byId('rafa-kotwiczna');

    expect(straitCaptain()->stormChance($ocean->theme))->toBe(40)
        ->and(straitCaptain(researched: [Technology::MapySztormowe])->stormChance($ocean->theme))->toBe(20);

    // flota na Oceanie z banderą na Rafie Kotwicznej od tiku 0: 3 stali/tik ×2
    $profile = straitCaptain(sea: OCEAN, owned: ['rafa-kotwiczna'], tick: 3, openedGates: [STRAIT => 0]);
    $profile->ensureWorldState(seaMap(OCEAN, $profile->worldSeed()));

    expect($profile->depositYield($rafa, $ocean->theme)->toArray())->toBe(['wood' => 0, 'steel' => 6, 'gold' => 0])
        ->and($profile->stockpile('rafa-kotwiczna')->toArray())->toBe(['wood' => 0, 'steel' => 18, 'gold' => 0]);
});

it('wyspy Oceanu się zdobywa i stawia na nich budynki, jak w Akwenie 1', function () {
    $profile = straitCaptain(xp: 450, sea: OCEAN, atIsland: 'rafa-kotwiczna', stockpiles: ['rafa-kotwiczna' => new Resources(wood: 30, steel: 20)]);
    $world = seaMap(OCEAN, $profile->worldSeed());
    $rafa = straitGraph()->sea(OCEAN)->islands->byId('rafa-kotwiczna');

    expect($profile->isAt($world, 'rafa-kotwiczna'))->toBeTrue();
    $gameId = GameId::new();
    $profile->startBattle('rafa-kotwiczna', $gameId);
    $outcome = $profile->settleBattle($gameId, 'won', $rafa->xpWin, $rafa->lootWin);

    expect($outcome['captured'])->toBeTrue()
        ->and($profile->ownsIsland('rafa-kotwiczna'))->toBeTrue()
        ->and($profile->buildingBlocker(BuildingType::Kopalnia, $rafa, $world))->toBeNull();

    $profile->buildBuilding(BuildingType::Kopalnia, $rafa, $world);
    // kopalnia podwaja urobek już podwojony przez motyw: (3 ×2) ×2
    expect($profile->depositYield($rafa, $world->theme())->steel)->toBe(12)
        ->and(array_map(static fn (LogEntry $e) => [$e->event, $e->data], $profile->log()))->toBe([
            [LogEvent::IslandCaptured, ['island' => 'rafa-kotwiczna']],
            [LogEvent::BuildingBuilt, ['building' => 'kopalnia', 'island' => 'rafa-kotwiczna']],
        ]);
});

it('konwój bez eskorty traci w sztormie przy wpłynięciu przez bramę podwójnie, jak w rejsie', function () {
    $graph = straitGraph();
    foreach (range(1, 400) as $attempt) {
        $profile = straitCaptain(openedGates: [STRAIT => 0], fleet: [OwnedShip::build(ShipType::Transportowiec)], cargo: new Resources(wood: 50), tick: $attempt);
        $seed = $profile->worldSeed();
        $outcome = $profile->passGate($graph->passage(Sea::START, STRAIT), seaMap(Sea::START, $seed), seaMap(OCEAN, $seed));
        if ('cargo-lost' === ($outcome['event']['effect'] ?? null)) {
            // 10% ładunku ×2 = 10, ale nie mniej niż minimum drewna ×2 = 10
            expect($outcome['event']['unescorted'])->toBeTrue()
                ->and($outcome['event']['cargoLost'])->toBe(['wood' => 10, 'steel' => 0, 'gold' => 0])
                // kartografia po sztormie dokłada drewno do ładowni
                ->and($profile->cargo()->wood)->toBe(40 + $outcome['cartography'] - $outcome['cartographyLost'])
                // sztorm trafia do dziennika za przejściem przez bramę, w tym samym tiku
                ->and(array_map(static fn (LogEntry $e) => [$e->tick, $e->event], $profile->log()))->toBe([
                    [$attempt + 1, LogEvent::GatePassed],
                    [$attempt + 1, LogEvent::Storm],
                ])
                ->and(array_slice($profile->log(), -1)[0]->data)->toBe(['effect' => 'cargo-lost', 'cargoLost' => ['wood' => 10, 'steel' => 0, 'gold' => 0], 'unescorted' => true]);

            return;
        }
    }
    $this->fail('No storm with cargo loss in 400 attempts');
});

it('przystań innego akwenu nie staje się celem kontrataku, gdy flota stoi na Oceanie', function () {
    // pod banderą tylko Zatoka Rozbitka (bezpieczna przystań Akwenu 1); 200 tików rzutów
    $profile = straitCaptain(
        sea: OCEAN, owned: ['zatoka-rozbitka'], tick: 200, openedGates: [STRAIT => 0],
        counterattacks: ['pending' => null, 'calmFrom' => 0, 'rolledTo' => 0, 'last' => null],
    );
    $profile->ensureWorldState(seaMap(OCEAN, $profile->worldSeed()));

    expect($profile->counterattack())->toBeNull()
        ->and($profile->ownsIsland('zatoka-rozbitka'))->toBeTrue();
});

it('otwarte bramy i akwen floty przechodzą przez snapshot', function () {
    $profile = straitCaptain(sea: OCEAN, openedGates: [STRAIT => 7]);
    $mapper = new ProfileSnapshotMapper();
    $restored = $mapper->toDomain((string) $profile->id(), json_decode(json_encode($mapper->toArray($profile)), true));

    expect($restored->openedGates())->toBe([STRAIT => 7])
        ->and($restored->currentSea())->toBe(OCEAN)
        ->and($restored->position())->toBe($profile->position())
        // profil sprzed bram nie ma żadnej otwartej
        ->and($mapper->toDomain((string) $profile->id(), ['name' => 'x'])->openedGates())->toBe([]);
});
