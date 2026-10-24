<?php

declare(strict_types=1);

use App\Domain\Expedition\BattleKind;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Encounter;
use App\Domain\Expedition\EncounterCatalog;
use App\Domain\Expedition\EncounterDecision;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\MarketRate;
use App\Domain\Expedition\MerchantPrices;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;
use Tests\Support\Encounters;

// Spotkania na morzu (#141): handlarz i pirat podczas rejsu.

const ENCOUNTER_SEED = 4242;

function encounterSea(int $seed = ENCOUNTER_SEED): WorldMap
{
    $graph = new StaticSeaGraph(new StaticIslandCatalog());

    return $graph->sea(Sea::START)->map($seed, $graph->passagesFrom(Sea::START));
}

/** @return array{0: array{x:int,y:int}, 1: array{x:int,y:int}} dwa sąsiednie sektory otwartego morza */
function openWaters(WorldMap $world): array
{
    for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
        for ($x = 0; $x < WorldMap::WIDTH - 1; ++$x) {
            $free = static fn (int $x, int $y) => null === $world->islandAt($x, $y) && null === $world->gateAt($x, $y);
            if ($free($x, $y) && $free($x + 1, $y)) {
                return [['x' => $x, 'y' => $y], ['x' => $x + 1, 'y' => $y]];
            }
        }
    }
    throw new LogicException('No open waters');
}

/** Kapitan na otwartym morzu z transportowcem i kutrem (ładownia 75). */
function encounterCaptain(?Encounter $encounter = null, ?Resources $cargo = null, int $seed = ENCOUNTER_SEED, int $tick = 0): CaptainProfile
{
    [$at] = openWaters(encounterSea($seed));

    return CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 0, [],
        [OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Kuter)],
        $cargo ?? Resources::none(), $seed, $at, ["{$at['x']}:{$at['y']}"],
        tick: $tick,
        encounter: $encounter,
    );
}

/** Kurs rynku Morza Rozbitków z portu handlowego (#140). */
function shipwreckRate(): MarketRate
{
    return (new StaticIslandCatalog())->tradePort()->rate;
}

function pendingEncounter(EncounterType $type, int $tick = 0): Encounter
{
    [$at] = openWaters(encounterSea());

    return new Encounter($type, $tick, Sea::START, $at['x'], $at['y']);
}

/**
 * Rejsy tam i z powrotem między dwoma sektorami otwartego morza. Spotkanie
 * rozstrzyga Encounters::evade(), żeby flota płynęła dalej.
 *
 * @return list<array{tick:int, event: array<string,mixed>|null, encounter: array<string,mixed>|null}>
 */
function shuttleVoyages(CaptainProfile $profile, WorldMap $world, int $voyages): array
{
    [$a, $b] = openWaters($world);
    $out = [];
    for ($i = 0; $i < $voyages; ++$i) {
        $target = $profile->position() === $a ? $b : $a;
        $outcome = $profile->sail($target['x'], $target['y'], $world);
        $out[] = ['tick' => $profile->tick(), 'event' => $outcome['event'], 'encounter' => $outcome['encounter']];
        Encounters::evade($profile);
    }

    return $out;
}

/** @return list<LogEntry> */
function encounterLog(CaptainProfile $profile, LogEvent $event): array
{
    return array_values(array_filter($profile->log(), static fn (LogEntry $e) => $e->event === $event));
}

it('spotkanie jest powtarzalne dla seeda i tiku', function () {
    $world = encounterSea();
    $first = shuttleVoyages(encounterCaptain(), $world, 200);
    $second = shuttleVoyages(encounterCaptain(), $world, 200);

    expect($first)->toBe($second)
        ->and(array_filter(array_column($first, 'encounter')))->not->toBeEmpty();
});

it('około 8% rejsów kończy się spotkaniem, z tego około 60% to handlarze', function () {
    $met = [];
    for ($seed = 1; $seed <= 10; ++$seed) {
        foreach (shuttleVoyages(encounterCaptain(seed: $seed), encounterSea($seed), 200) as $voyage) {
            if (null !== $voyage['encounter']) {
                $met[] = $voyage['encounter']['type'];
            }
        }
    }

    $rate = count($met) / 2000 * 100;
    $merchants = count(array_filter($met, static fn (string $t) => 'merchant' === $t)) / count($met) * 100;
    expect($rate)->toBeGreaterThan(5)->toBeLessThan(11)
        ->and($merchants)->toBeGreaterThan(50)->toBeLessThan(70);
});

it('w jednym rejsie najwyżej sztorm albo spotkanie', function () {
    $storms = 0;
    $encounters = 0;
    for ($seed = 1; $seed <= 300; ++$seed) {
        $world = encounterSea($seed);
        [, $b] = openWaters($world);
        // sektor nieodwiedzony: sztorm możliwy
        $outcome = encounterCaptain(seed: $seed)->sail($b['x'], $b['y'], $world);
        expect(null !== $outcome['event'] && null !== $outcome['encounter'])->toBeFalse();
        $storms += null !== $outcome['event'] ? 1 : 0;
        $encounters += null !== $outcome['encounter'] ? 1 : 0;
    }

    expect($storms)->toBeGreaterThan(0)->and($encounters)->toBeGreaterThan(0);
});

it('spotkanie nie wypada na sektorze wyspy ani bramy', function () {
    $world = encounterSea();
    $home = $world->islandPosition('mielizny');
    $away = ['x' => $home['x'] + ($home['x'] < WorldMap::WIDTH - 1 ? 1 : -1), 'y' => $home['y']];
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 0, [], null, Resources::none(), ENCOUNTER_SEED, $home, [],
    );

    $atIsland = 0;
    $atSea = 0;
    for ($i = 0; $i < 400; ++$i) {
        $target = $profile->position() === $home ? $away : $home;
        $outcome = $profile->sail($target['x'], $target['y'], $world);
        $atIsland += $target === $home && null !== $outcome['encounter'] ? 1 : 0;
        $atSea += $target === $away && null !== $outcome['encounter'] ? 1 : 0;
        Encounters::evade($profile);
    }

    expect($atIsland)->toBe(0)->and($atSea)->toBeGreaterThan(0);
});

it('spotkanie blokuje rejs do decyzji i trafia do dziennika', function () {
    $world = encounterSea();
    [$a, $b] = openWaters($world);
    $profile = encounterCaptain();
    // pierwszy rejs ze spotkaniem
    for ($i = 0; null === $profile->encounter(); ++$i) {
        $target = $profile->position() === $a ? $b : $a;
        $profile->sail($target['x'], $target['y'], $world);
        expect($i)->toBeLessThan(500);
    }
    $encounter = $profile->encounter();
    expect($encounter->tick)->toBe($profile->tick())
        ->and(['x' => $encounter->x, 'y' => $encounter->y])->toBe($profile->position())
        ->and(encounterLog($profile, LogEvent::EncounterMet))->toHaveCount(1);

    $target = $profile->position() === $a ? $b : $a;
    expect(fn () => $profile->sail($target['x'], $target['y'], $world))->toThrow(DomainException::class, 'Encounter pending');

    Encounters::evade($profile);
    $profile->sail($target['x'], $target['y'], $world);
    expect($profile->position())->toBe($target);
});

it('ucieczka przed piratem kosztuje tik i 20% każdego surowca w ładowni', function () {
    $profile = encounterCaptain(pendingEncounter(EncounterType::Pirate), new Resources(wood: 50, steel: 11, gold: 9), tick: 5);
    expect($profile->fleeCargoLoss()->toArray())->toBe(['wood' => 10, 'steel' => 2, 'gold' => 1]);

    $outcome = $profile->decideEncounter(EncounterDecision::Flee);

    expect($outcome['cargoLost']->toArray())->toBe(['wood' => 10, 'steel' => 2, 'gold' => 1])
        ->and($profile->cargo()->toArray())->toBe(['wood' => 40, 'steel' => 9, 'gold' => 8])
        ->and($profile->tick())->toBe(6)
        ->and($profile->encounter())->toBeNull();
    $entry = encounterLog($profile, LogEvent::EncounterResolved)[0];
    expect($entry->data)->toBe(['encounter' => 'pirate', 'decision' => 'flee', 'cargoLost' => ['wood' => 10, 'steel' => 2, 'gold' => 1]]);
});

it('okup kosztuje złoto akwenu z ładowni, a bez złota go nie ma', function () {
    $poor = encounterCaptain(pendingEncounter(EncounterType::Pirate), new Resources(wood: 30, gold: 9));
    expect(fn () => $poor->decideEncounter(EncounterDecision::Ransom))->toThrow(DomainException::class, 'Not enough gold for ransom')
        ->and($poor->encounter())->not->toBeNull();

    $rich = encounterCaptain(pendingEncounter(EncounterType::Pirate), new Resources(wood: 30, gold: 15), tick: 3);
    $outcome = $rich->decideEncounter(EncounterDecision::Ransom);

    expect(EncounterCatalog::ransom(Sea::START))->toBe(10)
        ->and($outcome['goldPaid'])->toBe(10)
        ->and($rich->cargo()->toArray())->toBe(['wood' => 30, 'steel' => 0, 'gold' => 5])
        ->and($rich->tick())->toBe(3)
        ->and($rich->encounter())->toBeNull()
        ->and(EncounterCatalog::ransom(StaticSeaGraph::STORM_OCEAN))->toBe(25)
        ->and(EncounterCatalog::ransom(StaticSeaGraph::MIST_SEA))->toBe(35);
});

it('minięcie handlarza nie ma skutków', function () {
    $profile = encounterCaptain(pendingEncounter(EncounterType::Merchant), new Resources(wood: 30), tick: 4);
    $profile->decideEncounter(EncounterDecision::Pass);

    expect($profile->encounter())->toBeNull()
        ->and($profile->tick())->toBe(4)
        ->and($profile->cargo()->toArray())->toBe(['wood' => 30, 'steel' => 0, 'gold' => 0]);
});

it('wybory pasują do rodzaju spotkania', function () {
    $merchant = encounterCaptain(pendingEncounter(EncounterType::Merchant), new Resources(wood: 30, gold: 20));
    $pirate = encounterCaptain(pendingEncounter(EncounterType::Pirate), new Resources(wood: 30, gold: 20));

    expect(fn () => $merchant->decideEncounter(EncounterDecision::Flee))->toThrow(DomainException::class, 'Not available in this encounter')
        ->and(fn () => $merchant->decideEncounter(EncounterDecision::Ransom))->toThrow(DomainException::class, 'Not available in this encounter')
        ->and(fn () => $pirate->decideEncounter(EncounterDecision::Pass))->toThrow(DomainException::class, 'Not available in this encounter')
        ->and(fn () => $pirate->tradeWithMerchant(new Resources(wood: 10), Resources::none(), shipwreckRate()))->toThrow(DomainException::class, 'Not available in this encounter')
        ->and(fn () => encounterCaptain()->decideEncounter(EncounterDecision::Pass))->toThrow(DomainException::class, 'No encounter pending');
});

it('handlarz liczy o 25% gorzej niż port: kupno za każdą zaczętą porcję, sprzedaż za pełne', function () {
    $port = new MerchantPrices(shipwreckRate(), 100);
    $merchant = encounterCaptain()->merchantPrices(shipwreckRate());

    // port: 5 drewna albo 3 stali za 1 złoto, sprzedaż po połowie ceny
    expect($merchant->pricePercent)->toBe(125)
        ->and($port->buyCost(new Resources(wood: 20)))->toBe(4)
        ->and($port->sellValue(new Resources(wood: 40)))->toBe(4)
        // handlarz: 4 drewna albo 2 stali za 1 złoto, sprzedaż 13 drewna albo 8 stali za 1 złoto
        ->and($merchant->buyCost(new Resources(wood: 20)))->toBe(5)
        ->and($merchant->buyCost(new Resources(wood: 21)))->toBe(6)
        ->and($merchant->sellValue(new Resources(wood: 40)))->toBe(3)
        ->and($merchant->buyCost(new Resources(steel: 6)))->toBe(3)
        ->and($merchant->sellValue(new Resources(steel: 6)))->toBe(0)
        ->and($merchant->sellValue(new Resources(steel: 8)))->toBe(1);
});

it('handel u handlarza: jedno zlecenie za tik, złoto ze sprzedaży opłaca kupno', function () {
    $profile = encounterCaptain(pendingEncounter(EncounterType::Merchant), new Resources(wood: 40), tick: 7);

    $outcome = $profile->tradeWithMerchant(new Resources(wood: 40), new Resources(steel: 6), shipwreckRate());

    expect($outcome)->toBe(['goldReceived' => 3, 'goldPaid' => 3])
        ->and($profile->cargo()->toArray())->toBe(['wood' => 0, 'steel' => 6, 'gold' => 0])
        ->and($profile->tick())->toBe(8)
        ->and($profile->encounter())->toBeNull();
    $entry = encounterLog($profile, LogEvent::EncounterResolved)[0];
    expect($entry->data)->toBe([
        'encounter' => 'merchant', 'decision' => 'trade',
        'sold' => ['wood' => 40, 'steel' => 0, 'gold' => 0], 'bought' => ['wood' => 0, 'steel' => 6, 'gold' => 0],
        'goldReceived' => 3, 'goldPaid' => 3,
    ]);
});

it('handel odrzuca zlecenie bez środków, bez miejsca w ładowni, ze złotem i puste', function () {
    $profile = encounterCaptain(pendingEncounter(EncounterType::Merchant), new Resources(wood: 70, gold: 4));

    expect(fn () => $profile->tradeWithMerchant(Resources::none(), new Resources(wood: 20), shipwreckRate()))->toThrow(DomainException::class, 'Not enough resources')
        ->and(fn () => $profile->tradeWithMerchant(new Resources(wood: 71), Resources::none(), shipwreckRate()))->toThrow(DomainException::class, 'Not enough resources')
        ->and(fn () => $profile->tradeWithMerchant(Resources::none(), new Resources(steel: 6), shipwreckRate()))->toThrow(DomainException::class, 'Cargo capacity exceeded')
        ->and(fn () => $profile->tradeWithMerchant(new Resources(gold: 1), Resources::none(), shipwreckRate()))->toThrow(DomainException::class, 'Merchant trades wood and steel for gold')
        ->and(fn () => $profile->tradeWithMerchant(Resources::none(), Resources::none(), shipwreckRate()))->toThrow(DomainException::class, 'Empty trade order')
        ->and($profile->encounter())->not->toBeNull()
        ->and($profile->cargo()->toArray())->toBe(['wood' => 70, 'steel' => 0, 'gold' => 4]);
});

it('napad startuje bitwę spotkania za tik, a wygrana ładuje łup do ładowni', function () {
    $profile = encounterCaptain(pendingEncounter(EncounterType::Merchant), new Resources(wood: 40), tick: 2);
    $ships = array_map(static fn (OwnedShip $s) => $s->id, $profile->fleet());
    $gameId = GameId::new();

    $profile->startEncounterBattle($gameId, $ships);
    expect($profile->battleKindFor($gameId))->toBe(BattleKind::Encounter)
        ->and($profile->encounterFor($gameId))->toBe(EncounterType::Merchant)
        ->and($profile->islandFor($gameId))->toBe(Sea::START)
        ->and($profile->encounter())->toBeNull()
        ->and($profile->tick())->toBe(3);

    $merchant = EncounterCatalog::fleet(EncounterType::Merchant, Sea::START);
    $outcome = $profile->settleBattle($gameId, 'won', $merchant->xpWin, $merchant->lootWin);

    // ładownia 75: 40 drewna + łup 30/15/10 = 95, najcenniejsze zostają, nadmiar drewna za burtą
    expect($profile->cargo()->toArray())->toBe(['wood' => 50, 'steel' => 15, 'gold' => 10])
        ->and($outcome['cargoLost']->toArray())->toBe(['wood' => 20, 'steel' => 0, 'gold' => 0])
        ->and($outcome['captured'])->toBeFalse()
        ->and($profile->stockpiles())->toBe([])
        ->and($profile->xp())->toBe(40);
    // pod reputację (#142): napad zapisany w rejestrze bitew i w dzienniku
    expect($profile->battles()[(string) $gameId]['encounter'])->toBe('merchant')
        ->and(encounterLog($profile, LogEvent::EncounterBattle)[0]->data)->toBe(['encounter' => 'merchant', 'result' => 'won', 'sea' => Sea::START]);
});

it('przegrana z piratem daje tylko XP, bez łupu', function () {
    $profile = encounterCaptain(pendingEncounter(EncounterType::Pirate), new Resources(wood: 10));
    $gameId = GameId::new();
    $profile->startEncounterBattle($gameId);

    $pirate = EncounterCatalog::fleet(EncounterType::Pirate, Sea::START);
    $profile->settleBattle($gameId, 'lost', $pirate->xpLoss, $pirate->lootLoss);

    expect($profile->xp())->toBe(15)
        ->and($profile->cargo()->toArray())->toBe(['wood' => 10, 'steel' => 0, 'gold' => 0])
        ->and(encounterLog($profile, LogEvent::EncounterBattle)[0]->data)->toBe(['encounter' => 'pirate', 'result' => 'lost', 'sea' => Sea::START]);
});

it('floty spotkań mieszczą się na planszy, a skład gracza ma limit 20% pól', function () {
    foreach ([Sea::START, StaticSeaGraph::STORM_OCEAN, StaticSeaGraph::MIST_SEA] as $sea) {
        foreach (EncounterType::cases() as $type) {
            $fleet = EncounterCatalog::fleet($type, $sea);
            $cells = array_sum(array_map(static fn (ShipType $t) => $t->length(), $fleet->fleet));
            expect($cells)->toBeLessThanOrEqual($fleet->squadCellLimit())
                ->and($fleet->xpLoss)->toBe(intdiv($fleet->xpWin, 4))
                ->and($fleet->lootLoss->isZero())->toBeTrue();
        }
        expect(EncounterCatalog::fleet(EncounterType::Merchant, $sea)->fleet)->toContain(ShipType::Transportowiec);
    }
});

it('spotkanie i bitwa spotkania przeżywają zapis i odczyt profilu', function () {
    $mapper = new ProfileSnapshotMapper();
    $profile = encounterCaptain(pendingEncounter(EncounterType::Pirate, 9), new Resources(wood: 5));
    $restored = $mapper->toDomain((string) $profile->id(), json_decode(json_encode($mapper->toArray($profile)), true));
    expect($restored->encounter()?->toArray())->toBe($profile->encounter()->toArray());

    $gameId = GameId::new();
    $restored->startEncounterBattle($gameId);
    $again = $mapper->toDomain((string) $profile->id(), json_decode(json_encode($mapper->toArray($restored)), true));
    expect($again->encounter())->toBeNull()
        ->and($again->encounterFor($gameId))->toBe(EncounterType::Pirate)
        ->and($again->battleKindFor($gameId))->toBe(BattleKind::Encounter);
});
