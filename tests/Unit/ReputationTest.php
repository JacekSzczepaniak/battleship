<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Encounter;
use App\Domain\Expedition\EncounterCatalog;
use App\Domain\Expedition\EncounterDecision;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\MarketGood;
use App\Domain\Expedition\MarketRate;
use App\Domain\Expedition\MarketSide;
use App\Domain\Expedition\MistSeaIslandCatalog;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Reputation;
use App\Domain\Expedition\ReputationTier;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// Reputacja kupiec ↔ korsarz (#142, D4 Epica J): oś, ceny, embargo i łowcy nagród.

const REPUTATION_SEED = 4242;

function reputationSea(int $seed = REPUTATION_SEED): WorldMap
{
    $graph = new StaticSeaGraph(new StaticIslandCatalog());

    return $graph->sea(Sea::START)->map($seed, $graph->passagesFrom(Sea::START));
}

/** @return array{x:int,y:int} sektor otwartego morza z sąsiadem po prawej, też otwartym */
function reputationOpenWater(WorldMap $world): array
{
    $free = static fn (int $x, int $y) => null === $world->islandAt($x, $y) && null === $world->gateAt($x, $y)
        && $world->tradePortPosition() !== ['x' => $x, 'y' => $y];
    for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
        for ($x = 0; $x < WorldMap::WIDTH - 1; ++$x) {
            if ($free($x, $y) && $free($x + 1, $y)) {
                return ['x' => $x, 'y' => $y];
            }
        }
    }
    throw new LogicException('No open waters');
}

/**
 * Kapitan z reputacją. Domyślnie na otwartym morzu Rozbitków z kutrem
 * i niszczycielem; $atPort stawia go w porcie handlowym akwenu.
 *
 * @param list<OwnedShip>|null $fleet
 */
function reputed(int $reputation, ?Resources $cargo = null, ?array $fleet = null, ?Encounter $encounter = null, int $seed = REPUTATION_SEED, bool $atPort = false): CaptainProfile
{
    $world = reputationSea($seed);
    $at = $atPort ? ($world->tradePortPosition() ?? throw new LogicException('Sea without trade port')) : reputationOpenWater($world);
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 0, [],
        $fleet ?? [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Niszczyciel)],
        $cargo ?? Resources::none(), $seed, $at, ["{$at['x']}:{$at['y']}"],
        tick: 10,
        encounter: $encounter,
        reputation: $reputation,
    );
    $profile->ensureWorldState($world);

    return $profile;
}

function reputationEncounter(EncounterType $type): Encounter
{
    $at = reputationOpenWater(reputationSea());

    return new Encounter($type, 10, Sea::START, $at['x'], $at['y']);
}

/** @return list<LogEntry> */
function reputationLog(CaptainProfile $profile): array
{
    return array_values(array_filter($profile->log(), static fn (LogEntry $e) => LogEvent::ReputationTier === $e->event));
}

it('oś reputacji: od −100 do +100, start od 0, wartość spoza osi przycięta', function () {
    expect(CaptainProfile::create('Jacek')->reputation()->value)->toBe(0)
        ->and(Reputation::clamped(130)->value)->toBe(100)
        ->and(Reputation::clamped(-130)->value)->toBe(-100)
        ->and((new Reputation(95))->change(20)->value)->toBe(100)
        ->and((new Reputation(-90))->change(-20)->value)->toBe(-100)
        ->and(fn () => new Reputation(101))->toThrow(InvalidArgumentException::class);
});

it('progi: od +40 kupiec (ceny 90%), od −40 korsarz (125%), od −70 embargo', function (int $value, ReputationTier $tier, int $percent) {
    $reputation = new Reputation($value);

    expect($reputation->tier())->toBe($tier)
        ->and($reputation->pricePercent())->toBe($percent)
        ->and($reputation->isEmbargo())->toBe(ReputationTier::Embargo === $tier)
        ->and(reputed($value)->marketPricePercent())->toBe($percent);
})->with([
    [100, ReputationTier::Merchant, 90],
    [40, ReputationTier::Merchant, 90],
    [39, ReputationTier::Neutral, 100],
    [0, ReputationTier::Neutral, 100],
    [-39, ReputationTier::Neutral, 100],
    [-40, ReputationTier::Corsair, 125],
    [-69, ReputationTier::Corsair, 125],
    [-70, ReputationTier::Embargo, 125],
    [-100, ReputationTier::Embargo, 125],
]);

it('transakcja na rynku portu daje +2 reputacji', function () {
    $profile = reputed(0, new Resources(wood: 10, gold: 5), atPort: true);
    $port = (new StaticIslandCatalog())->tradePort();

    $profile->trade($port, MarketSide::Buy, MarketGood::Wood, 1);
    $profile->trade($port, MarketSide::Sell, MarketGood::Wood, 1);

    expect($profile->reputation()->value)->toBe(4);
});

it('kupiec kupuje taniej, bo kupno liczy się od całego zlecenia, a korsarz drożej', function () {
    $port = (new StaticIslandCatalog())->tradePort();
    $buy = static function (int $reputation) use ($port): int {
        $profile = reputed($reputation, new Resources(gold: 10), [OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Kuter)], atPort: true);

        return $profile->trade($port, MarketSide::Buy, MarketGood::Wood, 10)['received']->wood;
    };

    // kurs Rozbitków 5 drewna za złoto: 10 złota przy 90% to 55 drewna, nie 50 z porcji po 1 złoto
    expect($buy(40))->toBe(55)
        ->and($buy(0))->toBe(50)
        ->and($buy(-40))->toBe(40)
        ->and($port->rate->buyTotal(MarketGood::Steel, 10, 125))->toBe(24)
        ->and($port->rate->buyTotal(MarketGood::Steel, 1, MarketRate::BASE_PRICE_PERCENT))->toBe(3);
});

it('ceny mapy, sprzedaży statku i handlarza idą za reputacją', function () {
    $mistPort = (new MistSeaIslandCatalog())->tradePort();
    $offer = $mistPort->chart ?? throw new LogicException('Mist port sells a chart');
    $rate = (new StaticIslandCatalog())->tradePort()->rate;
    $merchant = reputed(40);
    $corsair = reputed(-40);
    $ship = OwnedShip::build(ShipType::Niszczyciel);

    expect($merchant->chartPrice($offer))->toBe(135)
        ->and($corsair->chartPrice($offer))->toBeGreaterThan($offer->price)
        ->and($merchant->shipSaleValue($ship, $rate))->toBe($rate->goldValue($merchant->scrapValue($ship), 90))
        ->and($corsair->shipSaleValue($ship, $rate))->toBe($rate->goldValue($corsair->scrapValue($ship), 125))
        ->and($merchant->shipSaleValue($ship, $rate))->toBeGreaterThan($corsair->shipSaleValue($ship, $rate))
        ->and($merchant->merchantPrices($rate)->pricePercent)->toBe(intdiv(90 * 125, 100))
        ->and($corsair->merchantPrices($rate)->pricePercent)->toBe(intdiv(125 * 125, 100));
});

it('zlecenie u handlarza daje +2 reputacji', function () {
    $profile = reputed(0, new Resources(wood: 20), encounter: reputationEncounter(EncounterType::Merchant));

    $profile->tradeWithMerchant(new Resources(wood: 13), Resources::none(), (new StaticIslandCatalog())->tradePort()->rate);

    expect($profile->reputation()->value)->toBe(2);
});

it('napad na handlarza kosztuje −20 już przy starcie bitwy, bez względu na wynik', function () {
    $profile = reputed(10, encounter: reputationEncounter(EncounterType::Merchant));
    $gameId = GameId::new();

    $profile->startEncounterBattle($gameId);
    expect($profile->reputation()->value)->toBe(-10);

    $merchant = EncounterCatalog::fleet(EncounterType::Merchant, Sea::START);
    $profile->settleBattle($gameId, 'won', $merchant->xpWin, $merchant->lootWin);
    expect($profile->reputation()->value)->toBe(-10);
});

it('wygrana z piratem daje +5, przegrana i ucieczka nic', function () {
    $pirate = EncounterCatalog::fleet(EncounterType::Pirate, Sea::START);
    $fight = static function (string $result): int {
        $profile = reputed(0, encounter: reputationEncounter(EncounterType::Pirate));
        $gameId = GameId::new();
        $profile->startEncounterBattle($gameId);
        $profile->settleBattle($gameId, $result, 0);
        // druga próba rozliczenia nie dolicza drugi raz
        $profile->settleBattle($gameId, $result, 0);

        return $profile->reputation()->value;
    };
    $fled = reputed(0, encounter: reputationEncounter(EncounterType::Pirate));
    $fled->decideEncounter(EncounterDecision::Flee);

    expect($fight('won'))->toBe(5)
        ->and($fight('lost'))->toBe(0)
        ->and($fled->reputation()->value)->toBe(0)
        ->and($pirate->xpWin)->toBeGreaterThan(0);
});

it('przejście progu trafia do dziennika, zmiana w obrębie progu nie', function (int $from, string $fromTier, string $toTier) {
    $profile = reputed($from, encounter: reputationEncounter(EncounterType::Merchant));
    $profile->startEncounterBattle(GameId::new());

    $entries = reputationLog($profile);
    expect($entries)->toHaveCount(1)
        ->and($entries[0]->tick)->toBe(11)
        ->and($entries[0]->data)->toBe(['from' => $fromTier, 'to' => $toTier, 'reputation' => $from + Reputation::RAID]);

    $quiet = reputed(-80, encounter: reputationEncounter(EncounterType::Merchant));
    $quiet->startEncounterBattle(GameId::new());
    expect(reputationLog($quiet))->toBe([]);
})->with([
    'kupiec → neutralny' => [45, 'merchant', 'neutral'],
    'neutralny → korsarz' => [-25, 'neutral', 'corsair'],
    'korsarz → embargo' => [-55, 'corsair', 'embargo'],
]);

it('embargo: port odmawia handlu, sprzedaży statku i mapy, a handlarz handlu — nic się nie zmienia', function () {
    $port = (new StaticIslandCatalog())->tradePort();
    $mistPort = (new MistSeaIslandCatalog())->tradePort();
    $profile = reputed(-70, new Resources(wood: 20, gold: 50), [OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Kuter)], atPort: true);
    $kuter = $profile->fleet()[1];
    $merchant = reputed(-70, new Resources(wood: 20), encounter: reputationEncounter(EncounterType::Merchant));

    expect(fn () => $profile->trade($port, MarketSide::Buy, MarketGood::Wood, 1))->toThrow(DomainException::class, 'Embargo')
        ->and(fn () => $profile->sellShip($kuter->id, $port))->toThrow(DomainException::class, 'Embargo')
        ->and(fn () => $profile->buySeaChart($mistPort))->toThrow(DomainException::class, 'Embargo')
        ->and(fn () => $merchant->tradeWithMerchant(new Resources(wood: 13), Resources::none(), $port->rate))->toThrow(DomainException::class, 'Embargo')
        ->and($profile->cargo())->toEqual(new Resources(wood: 20, gold: 50))
        ->and($profile->fleet())->toHaveCount(2)
        ->and($profile->tick())->toBe(10)
        ->and($profile->reputation()->value)->toBe(-70);
});

it('przy embargu zamiast handlarza przychodzą łowcy nagród, piraci bez zmian', function () {
    $hunters = 0;
    for ($seed = 1; $seed <= 400; ++$seed) {
        $world = reputationSea($seed);
        $at = reputationOpenWater($world);
        $neutral = reputed(0, seed: $seed)->sail($at['x'] + 1, $at['y'], $world)['encounter'];
        $outlaw = reputed(-70, seed: $seed)->sail($at['x'] + 1, $at['y'], $world)['encounter'];

        $expected = match ($neutral['type'] ?? null) {
            'merchant' => 'bounty-hunters',
            default => $neutral['type'] ?? null,
        };
        expect($outlaw['type'] ?? null)->toBe($expected);
        $hunters += 'bounty-hunters' === $expected ? 1 : 0;
        // korsarz bez embarga wciąż spotyka handlarzy
        expect(reputed(-69, seed: $seed)->sail($at['x'] + 1, $at['y'], $world)['encounter']['type'] ?? null)->toBe($neutral['type'] ?? null);
    }
    expect($hunters)->toBeGreaterThan(0);
});

it('łowcy nagród nie przychodzą do floty bez sprawnego statku z bronią', function () {
    $damaged = OwnedShip::build(ShipType::Kuter);
    $damaged->markDamaged();
    $unarmed = [OwnedShip::build(ShipType::Transportowiec), $damaged];
    for ($seed = 1; $seed <= 400; ++$seed) {
        $world = reputationSea($seed);
        $at = reputationOpenWater($world);
        $neutral = reputed(0, fleet: $unarmed, seed: $seed)->sail($at['x'] + 1, $at['y'], $world)['encounter'];
        $outlaw = reputed(-70, fleet: $unarmed, seed: $seed)->sail($at['x'] + 1, $at['y'], $world)['encounter'];

        expect($outlaw['type'] ?? null)->toBe('merchant' === ($neutral['type'] ?? null) ? null : ($neutral['type'] ?? null));
    }
});

it('z łowcami nagród jest tylko bitwa: bez minięcia, ucieczki, okupu i handlu', function () {
    $profile = reputed(-80, new Resources(wood: 20, gold: 100), encounter: reputationEncounter(EncounterType::BountyHunters));

    foreach (EncounterDecision::cases() as $decision) {
        expect(fn () => $profile->decideEncounter($decision))->toThrow(DomainException::class, 'Not available in this encounter');
    }
    expect(fn () => $profile->tradeWithMerchant(new Resources(wood: 13), Resources::none(), (new StaticIslandCatalog())->tradePort()->rate))
        ->toThrow(DomainException::class, 'Not available in this encounter');

    $gameId = GameId::new();
    $profile->startEncounterBattle($gameId);
    $fleet = EncounterCatalog::fleet(EncounterType::BountyHunters, Sea::START);
    $profile->settleBattle($gameId, 'won', $fleet->xpWin, $fleet->lootWin);

    // wygrana z łowcami nie zmienia reputacji: z embarga wyprowadzają tylko piraci
    expect($profile->encounter())->toBeNull()
        ->and($profile->encounterFor($gameId))->toBe(EncounterType::BountyHunters)
        ->and($profile->reputation()->value)->toBe(-80);
});

it('łowcy nagród to piraci akwenu z niszczycielem więcej i nagrodami ×1,25', function (string $seaId) {
    $pirate = EncounterCatalog::fleet(EncounterType::Pirate, $seaId);
    $hunters = EncounterCatalog::fleet(EncounterType::BountyHunters, $seaId);
    $scaled = static fn (int $n) => (int) round($n * 1.25);

    expect($hunters->name)->toBe('Łowcy nagród')
        ->and($hunters->fleet)->toBe([...$pirate->fleet, ShipType::Niszczyciel])
        ->and([$hunters->boardWidth, $hunters->boardHeight])->toBe([$pirate->boardWidth, $pirate->boardHeight])
        ->and($hunters->xpWin)->toBe($scaled($pirate->xpWin))
        ->and($hunters->xpLoss)->toBe(intdiv($hunters->xpWin, 4))
        ->and($hunters->lootWin->toArray())->toBe(array_map($scaled, $pirate->lootWin->toArray()))
        ->and($hunters->lootLoss->isZero())->toBeTrue()
        // flota mieści się w limicie 20% pól planszy, jak inne floty spotkań
        ->and(array_sum(array_map(static fn (ShipType $t) => $t->length(), $hunters->fleet)))->toBeLessThanOrEqual($hunters->squadCellLimit());
})->with([Sea::START, StaticSeaGraph::STORM_OCEAN, StaticSeaGraph::MIST_SEA, StaticSeaGraph::BLACK_PEARL]);

it('reputacja przeżywa zapis i odczyt profilu; profil bez klucza startuje od 0, spoza osi jest przycięty', function () {
    $mapper = new ProfileSnapshotMapper();
    $profile = reputed(-55);
    $state = $mapper->toArray($profile);

    expect($state['reputation'])->toBe(-55)
        ->and($mapper->toDomain((string) $profile->id(), $state)->reputation()->value)->toBe(-55);

    unset($state['reputation']);
    expect($mapper->toDomain((string) $profile->id(), $state)->reputation()->value)->toBe(0);

    $state['reputation'] = 250;
    expect($mapper->toDomain((string) $profile->id(), $state)->reputation()->value)->toBe(100);
});
