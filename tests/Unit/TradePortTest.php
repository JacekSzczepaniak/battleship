<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\MarketGood;
use App\Domain\Expedition\MarketRate;
use App\Domain\Expedition\MarketSide;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaTheme;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\TradePort;
use App\Domain\Expedition\WorldMap;

// Port handlowy na neutralnej wyspie każdego akwenu i rynek (#140).

const TRADE_SEED = 4242;

function tradeGraph(): StaticSeaGraph
{
    return new StaticSeaGraph(new StaticIslandCatalog());
}

function tradeMap(string $seaId = Sea::START, int $worldSeed = TRADE_SEED): WorldMap
{
    $graph = tradeGraph();

    return $graph->sea($seaId)->map($worldSeed, $graph->passagesFrom($seaId));
}

function shipwreckPort(): TradePort
{
    return (new StaticIslandCatalog())->tradePort();
}

/**
 * Kapitan zakotwiczony w porcie handlowym Morza Rozbitków.
 *
 * @param list<OwnedShip>|null $fleet null = kuter i niszczyciel (ładownia 35)
 */
function trader(?Resources $cargo = null, ?Resources $stock = null, ?array $fleet = null): CaptainProfile
{
    $port = shipwreckPort();
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 0, [],
        $fleet ?? [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Niszczyciel)],
        $cargo ?? Resources::none(),
        TRADE_SEED, tradeMap()->tradePortPosition(),
        tick: 10,
        stockpiles: null !== $stock ? [$port->id => $stock] : [],
    );
    $profile->ensureWorldState(tradeMap());

    return $profile;
}

it('kurs 5 drewna i 3 stali za 1 złoto sprzedaje po połowie ceny: 10 drewna i 6 stali za 1 złoto', function () {
    $rate = new MarketRate(woodPerGold: 5, steelPerGold: 3);

    expect($rate->buyAmount(MarketGood::Wood))->toBe(5)
        ->and($rate->buyAmount(MarketGood::Steel))->toBe(3)
        ->and($rate->sellAmount(MarketGood::Wood))->toBe(10)
        ->and($rate->sellAmount(MarketGood::Steel))->toBe(6)
        ->and($rate->toArray())->toBe(['wood' => ['buy' => 5, 'sell' => 10], 'steel' => ['buy' => 3, 'sell' => 6]]);
});

it('mnożnik ceny pod reputację: drożej daje mniej towaru za złoto i wymaga więcej przy sprzedaży', function () {
    $rate = new MarketRate(woodPerGold: 5, steelPerGold: 3);

    // +25% (handlarz z #141, embargo z #142): 5 / 1,25 = 4; 10 × 1,25 = 12,5 → 13
    expect($rate->buyAmount(MarketGood::Wood, 125))->toBe(4)
        ->and($rate->sellAmount(MarketGood::Wood, 125))->toBe(13)
        // −10%: 5 / 0,9 = 5,55 → 5; 6 × 0,9 = 5,4 → 6
        ->and($rate->buyAmount(MarketGood::Wood, 90))->toBe(5)
        ->and($rate->sellAmount(MarketGood::Steel, 90))->toBe(6)
        // złoto zawsze kupi choć 1 sztukę
        ->and($rate->buyAmount(MarketGood::Steel, 1000))->toBe(1);
});

it('wartość surowców w złocie liczy drewno i stal razem po kursie sprzedaży, w dół z całości', function () {
    $rate = new MarketRate(woodPerGold: 5, steelPerGold: 3);

    // 15/10 + 10/6 = 1,5 + 1,67 = 3,17 → 3 (osobno byłoby 1 + 1)
    expect($rate->goldValue(new Resources(wood: 15, steel: 10)))->toBe(3)
        ->and($rate->goldValue(new Resources(wood: 9, gold: 2)))->toBe(2)
        ->and($rate->goldValue(Resources::none()))->toBe(0);
});

it('akweny 1–3 mają port handlowy z własnym kursem: na Oceanie tańsza stal, na Morzu Mgieł droższe drewno', function () {
    $ports = array_map(static fn (Sea $sea) => $sea->tradePort, portSeas());

    expect(array_map(static fn (?TradePort $p) => [$p?->id, $p?->name], $ports))->toBe([
        ['targ-rozbitkow', 'Targ Rozbitków'],
        ['faktoria-kotwic', 'Faktoria Kotwic'],
        ['bazar-we-mgle', 'Bazar we Mgle'],
    ])
        ->and(array_map(static fn (TradePort $p) => $p->rate->toArray(), $ports))->toBe([
            ['wood' => ['buy' => 5, 'sell' => 10], 'steel' => ['buy' => 3, 'sell' => 6]],
            ['wood' => ['buy' => 5, 'sell' => 10], 'steel' => ['buy' => 4, 'sell' => 8]],
            ['wood' => ['buy' => 3, 'sell' => 6], 'steel' => ['buy' => 3, 'sell' => 6]],
        ])
        ->and(tradeGraph()->tradePort('faktoria-kotwic'))->toEqual($ports[1])
        ->and(tradeGraph()->tradePort('zatoka-rozbitka'))->toBeNull()
        // tajny akwen 4 nie ma portu: po handel wraca się na Morze Mgieł (#143)
        ->and(tradeGraph()->sea(StaticSeaGraph::BLACK_PEARL)->tradePort)->toBeNull();

    // brak arbitrażu: najtańsze kupno na dowolnym akwenie daje mniej towaru, niż wymaga najlepsza sprzedaż
    foreach (MarketGood::cases() as $good) {
        $maxBuy = max(array_map(static fn (TradePort $p) => $p->rate->buyAmount($good), $ports));
        $minSell = min(array_map(static fn (TradePort $p) => $p->rate->sellAmount($good), $ports));
        expect($maxBuy)->toBeLessThan($minSell);
    }
});

it('port nie należy do trasy wysp: nie ma bitwy, bandery, złoża ani kontrataków i nie liczy się do pełnej bandery', function () {
    foreach (portSeas() as $sea) {
        $ids = array_map(static fn (Island $i) => $i->id, $sea->islands->all());
        expect($ids)->not->toContain($sea->tradePort->id)
            ->and($sea->islands->byId($sea->tradePort->id))->toBeNull();
    }

    $map = tradeMap();
    expect(array_keys($map->islands()))->not->toContain('targ-rozbitkow')
        ->and($map->islandAt(...array_values($map->tradePortPosition())))->toBeNull()
        ->and($map->deposits())->not->toHaveKey('targ-rozbitkow');
});

it('id portu nie może powtarzać wyspy, bo magazyny szuka się po id w całym świecie', function () {
    $clash = new Sea('akwen-x', 'X', new SeaTheme(), new StaticIslandCatalog(), [], new TradePort('mielizny', 'Mielizny', '', new MarketRate(5, 3)));

    expect(fn () => new StaticSeaGraph(new StaticIslandCatalog(), [$clash]))
        ->toThrow(InvalidArgumentException::class, 'Island ids must be unique across seas');
});

it('sektor portu losuje seed akwenu osobnym rzutem: we wnętrzu mapy, nie obok wyspy i nie na bramie, bez zmiany rozkładu wysp', function () {
    foreach ([1, 7, TRADE_SEED, 99_999] as $seed) {
        foreach (portSeas() as $sea) {
            $map = tradeMap($sea->id, $seed);
            $pos = $map->tradePortPosition();
            $without = WorldMap::generate($sea->seed($seed), array_map(static fn (Island $i) => $i->id, $sea->islands->all()));

            expect($pos)->not->toBeNull()
                ->and($pos['x'])->toBeGreaterThan(0)->toBeLessThan(WorldMap::WIDTH - 1)
                ->and($pos['y'])->toBeGreaterThan(0)->toBeLessThan(WorldMap::HEIGHT - 1)
                ->and(array_filter($map->islands(), static fn (array $i) => max(abs($i['x'] - $pos['x']), abs($i['y'] - $pos['y'])) <= 1))->toBe([])
                ->and(array_values($map->gates()))->not->toContain($pos)
                ->and($map->islands())->toBe($without->islands())
                ->and(tradeMap($sea->id, $seed)->tradePortPosition())->toBe($pos)
                ->and($map->tradePortId())->toBe($sea->tradePort->id);
        }
    }
});

it('kupno płaci złotem najpierw z magazynu portu, resztę z ładowni, a towar trafia do ładowni za 1 tik', function () {
    $profile = trader(cargo: new Resources(gold: 5), stock: new Resources(gold: 2));

    $outcome = $profile->trade(shipwreckPort(), MarketSide::Buy, MarketGood::Wood, 3);

    expect($outcome['paid'])->toEqual(new Resources(gold: 3))
        ->and($outcome['received'])->toEqual(new Resources(wood: 15))
        ->and($profile->stockpile('targ-rozbitkow'))->toEqual(Resources::none())
        ->and($profile->cargo())->toEqual(new Resources(wood: 15, gold: 4))
        ->and($profile->tick())->toBe(11);

    $entry = $profile->log()[array_key_last($profile->log())];
    expect($entry->event)->toBe(LogEvent::MarketTrade)
        ->and($entry->tick)->toBe(11)
        ->and($entry->data)->toBe([
            'island' => 'targ-rozbitkow', 'side' => 'buy', 'good' => 'wood',
            'paid' => ['wood' => 0, 'steel' => 0, 'gold' => 3], 'received' => ['wood' => 15, 'steel' => 0, 'gold' => 0],
        ]);
});

it('sprzedaż oddaje towar po połowie ceny kupna i daje złoto do ładowni', function () {
    $profile = trader(cargo: new Resources(steel: 8), stock: new Resources(steel: 5));

    $outcome = $profile->trade(shipwreckPort(), MarketSide::Sell, MarketGood::Steel, 2);

    // 2 złota × 6 stali: 5 z magazynu, 7 z ładowni
    expect($outcome['paid'])->toEqual(new Resources(steel: 12))
        ->and($outcome['received'])->toEqual(new Resources(gold: 2))
        ->and($profile->stockpile('targ-rozbitkow'))->toEqual(Resources::none())
        ->and($profile->cargo())->toEqual(new Resources(steel: 1, gold: 2))
        ->and($profile->tick())->toBe(11);
});

it('transakcja bez środków jest odrzucana i niczego nie zmienia', function () {
    $profile = trader(cargo: new Resources(wood: 15, gold: 1), stock: new Resources(gold: 1));

    expect(fn () => $profile->trade(shipwreckPort(), MarketSide::Buy, MarketGood::Steel, 3))->toThrow(DomainException::class, 'Not enough resources')
        ->and(fn () => $profile->trade(shipwreckPort(), MarketSide::Sell, MarketGood::Wood, 2))->toThrow(DomainException::class, 'Not enough resources')
        ->and(fn () => $profile->trade(shipwreckPort(), MarketSide::Buy, MarketGood::Wood, 0))->toThrow(InvalidArgumentException::class)
        ->and($profile->cargo())->toEqual(new Resources(wood: 15, gold: 1))
        ->and($profile->stockpile('targ-rozbitkow'))->toEqual(new Resources(gold: 1))
        ->and($profile->tick())->toBe(10)
        ->and($profile->log())->toBe([]);
});

it('transakcja bez miejsca w ładowni jest odrzucana; złoto z magazynu nie zwalnia ładowni, złoto z ładowni tak', function () {
    // ładownia 35: 30 drewna + 5 złota, pełna
    $profile = trader(cargo: new Resources(wood: 30, gold: 5), stock: new Resources(gold: 5));

    // 1 złoto z magazynu → 5 drewna nie mieści się
    expect(fn () => $profile->trade(shipwreckPort(), MarketSide::Buy, MarketGood::Wood, 1))->toThrow(DomainException::class, 'Cargo capacity exceeded')
        ->and($profile->cargo())->toEqual(new Resources(wood: 30, gold: 5))
        ->and($profile->tick())->toBe(10);

    // bez złota w magazynie płaci ładownia: −1 złoto, +3 stali też się nie mieści, ale sprzedaż drewna zwalnia miejsce
    $profile = trader(cargo: new Resources(wood: 30, gold: 5));
    expect(fn () => $profile->trade(shipwreckPort(), MarketSide::Buy, MarketGood::Steel, 1))->toThrow(DomainException::class, 'Cargo capacity exceeded');
    $profile->trade(shipwreckPort(), MarketSide::Sell, MarketGood::Wood, 3);
    expect($profile->cargo())->toEqual(new Resources(gold: 8));
});

it('sprzedaż statku daje złoto za zwrot ze złomowania przeliczony po kursie sprzedaży akwenu, za 1 tik', function () {
    $niszczyciel = OwnedShip::build(ShipType::Niszczyciel);
    $wrak = OwnedShip::build(ShipType::Lotniskowiec);
    $wrak->markDamaged();
    $tratwa = OwnedShip::build(ShipType::Tratwa);
    $profile = trader(fleet: [OwnedShip::build(ShipType::Kuter), $niszczyciel, $wrak, $tratwa]);
    $rate = shipwreckPort()->rate;

    // niszczyciel 15/10 → 3; lotniskowiec uszkodzony 10/5 → 1 + 0,83 = 1; tratwa nic
    expect($profile->shipSaleValue($niszczyciel, $rate))->toBe(3)
        ->and($profile->shipSaleValue($wrak, $rate))->toBe(1)
        ->and($profile->shipSaleValue($tratwa, $rate))->toBe(0)
        // na Oceanie stal droższa w sprzedaży: 15/10 + 10/8 = 2,75 → 2
        ->and($profile->shipSaleValue($niszczyciel, tradeGraph()->tradePort('faktoria-kotwic')->rate))->toBe(2);

    expect($profile->sellShip($niszczyciel->id, shipwreckPort()))->toBe(3)
        ->and($profile->cargo())->toEqual(new Resources(gold: 3))
        ->and(array_map(static fn (OwnedShip $s) => $s->type, $profile->fleet()))->toBe([ShipType::Kuter, ShipType::Lotniskowiec, ShipType::Tratwa])
        ->and($profile->tick())->toBe(11);

    $entry = $profile->log()[array_key_last($profile->log())];
    expect($entry->event)->toBe(LogEvent::ShipSold)
        ->and($entry->data)->toBe(['ship' => 'niszczyciel', 'variant' => 'torpedowy', 'island' => 'targ-rozbitkow', 'gold' => 3]);
});

it('sprzedaż statku ma blokady złomowania i jest odrzucana, gdy ładownia bez niego nie pomieści ładunku ze złotem', function () {
    $kuter = OwnedShip::build(ShipType::Kuter);
    $niszczyciel = OwnedShip::build(ShipType::Niszczyciel);

    // ostatnia sprawna jednostka
    $profile = trader(fleet: [$kuter]);
    expect(fn () => $profile->sellShip($kuter->id, shipwreckPort()))->toThrow(DomainException::class, 'Cannot scrap the last seaworthy ship')
        ->and(fn () => $profile->sellShip('nope', shipwreckPort()))->toThrow(DomainException::class, 'Ship not found');

    // bez niszczyciela zostaje ładownia 15: 13 drewna + 3 złota się nie mieści
    $profile = trader(cargo: new Resources(wood: 13), fleet: [$kuter, $niszczyciel]);
    expect(fn () => $profile->sellShip($niszczyciel->id, shipwreckPort()))->toThrow(DomainException::class, 'Cargo capacity exceeded')
        ->and($profile->fleet())->toHaveCount(2)
        ->and($profile->tick())->toBe(10);

    // 12 drewna + 3 złota = 15 już tak
    $profile = trader(cargo: new Resources(wood: 12), fleet: [$kuter, $niszczyciel]);
    expect($profile->sellShip($niszczyciel->id, shipwreckPort()))->toBe(3)
        ->and($profile->cargo())->toEqual(new Resources(wood: 12, gold: 3));
});

it('flota stoi w porcie tylko na jego sektorze', function () {
    $profile = trader();
    expect($profile->isAtTradePort(tradeMap()))->toBeTrue()
        ->and($profile->marketPricePercent())->toBe(MarketRate::BASE_PRICE_PERCENT);

    $elsewhere = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 0, [], null, Resources::none(), TRADE_SEED);
    expect($elsewhere->isAtTradePort(tradeMap()))->toBeFalse();
});

/** @return list<Sea> akweny gry z portem handlowym (akwen 4 go nie ma, #143) */
function portSeas(): array
{
    return array_values(array_filter(tradeGraph()->seas(), static fn (Sea $sea) => null !== $sea->tradePort));
}
