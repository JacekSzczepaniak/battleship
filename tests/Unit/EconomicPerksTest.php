<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Encounter;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\MarketGood;
use App\Domain\Expedition\MarketSide;
use App\Domain\Expedition\MistSeaIslandCatalog;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Perk;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\Specializations;
use App\Domain\Expedition\SpecializationSource;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// Perki ścieżki ekonomicznej (#162, D4 Epica K): ładownia, ceny, budowa, magazyn złóż, handlarz.

/** Punkty ekonomiczne na dany poziom (D2: progi 20, 60, 120, 200, 300). */
function economyPoints(int $level): int
{
    return [0, 20, 60, 120, 200, 300][$level];
}

/**
 * Kapitan na danym poziomie ścieżki ekonomicznej (punkty z odkrytych sektorów: 1 sektor = 1 punkt).
 *
 * @param list<OwnedShip>|null $fleet
 * @param list<Technology>     $researched
 * @param array<string, mixed> $extra      dodatkowe argumenty fromSnapshot
 */
function tradesman(int $level, ?array $fleet = null, ?Resources $cargo = null, array $researched = [], int $reputation = 0, array $extra = []): CaptainProfile
{
    return CaptainProfile::fromSnapshot(...$extra + [
        'id' => CaptainProfile::create('Jacek')->id(),
        'name' => 'Jacek',
        'xp' => 0,
        'battles' => [],
        'fleet' => $fleet ?? [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Niszczyciel)],
        'cargo' => $cargo ?? Resources::none(),
        'tick' => 10,
        'researched' => $researched,
        'reputation' => $reputation,
        'specializations' => (new Specializations())->add(SpecializationSource::Discovery, economyPoints($level)),
    ]);
}

function perkIsland(string $id): Island
{
    return (new StaticIslandCatalog())->byId($id) ?? throw new LogicException("No island $id");
}

it('poziomy z punktów ekonomicznych odpowiadają perkom D4', function (int $level, Perk $perk) {
    expect(tradesman($level)->hasPerk($perk))->toBeTrue()
        ->and(tradesman($level - 1)->hasPerk($perk))->toBeFalse();
})->with([
    'ładownia' => [1, Perk::LargerHold],
    'ceny −5%' => [2, Perk::MarketDiscount],
    'budowa' => [3, Perk::CheaperShipbuilding],
    'magazyn złóż' => [4, Perk::DepositStorage],
    'mistrz handlu' => [5, Perk::MasterTrader],
]);

it('ładownia +10% od poziomu 1, sumuje się z Pojemnymi ładowniami w procentach bazowej', function () {
    // kuter 15 + niszczyciel 20
    expect(tradesman(0)->cargoCapacity())->toBe(35)
        // 15 × 110% = 16 (w dół), 20 × 110% = 22
        ->and(tradesman(1)->cargoCapacity())->toBe(38)
        // Pojemne ładownie: 22 + 30
        ->and(tradesman(0, researched: [Technology::PojemneLadownie])->cargoCapacity())->toBe(52)
        // oba: 15 × 160% = 24, 20 × 160% = 32
        ->and(tradesman(1, researched: [Technology::PojemneLadownie])->cargoCapacity())->toBe(56)
        ->and(tradesman(1)->shipCargoCapacity(ShipType::Transportowiec))->toBe(66);
});

it('większa ładownia mieści więcej ładunku', function () {
    $profile = tradesman(1, extra: ['stockpiles' => ['zatoka-rozbitka' => new Resources(wood: 50)]]);

    $profile->loadCargo('zatoka-rozbitka', new Resources(wood: 38));

    expect($profile->cargo()->wood)->toBe(38)
        ->and(fn () => $profile->loadCargo('zatoka-rozbitka', new Resources(wood: 1)))->toThrow(DomainException::class, 'Cargo capacity exceeded');
});

it('mnożnik ceny: perk 95% od poziomu 2 i 90% od poziomu 5, razy reputacja, w dół', function (int $level, int $reputation, int $perk, int $total) {
    $profile = tradesman($level, reputation: $reputation);

    expect($profile->marketPerkPercent())->toBe($perk)
        ->and($profile->marketPricePercent())->toBe($total);
})->with([
    'poziom 1, neutralny' => [1, 0, 100, 100],
    'poziom 2, neutralny' => [2, 0, 95, 95],
    'poziom 4, neutralny' => [4, 0, 95, 95],
    'poziom 5, neutralny' => [5, 0, 90, 90],
    // 90 × 95 / 100 = 85,5
    'poziom 2, kupiec' => [2, 40, 95, 85],
    'poziom 5, kupiec' => [5, 40, 90, 81],
    // 125 × 95 / 100 = 118,75
    'poziom 2, korsarz' => [2, -40, 95, 118],
    'poziom 5, korsarz' => [5, -40, 90, 112],
]);

it('kupno na rynku portu liczy się z perkiem i reputacją', function () {
    $port = (new StaticIslandCatalog())->tradePort();
    $buy = static function (int $level, int $reputation) use ($port): int {
        $profile = tradesman($level, [OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Kuter)], new Resources(gold: 10), reputation: $reputation);

        return $profile->trade($port, MarketSide::Buy, MarketGood::Wood, 10)['received']->wood;
    };

    // kurs Rozbitków 5 drewna za złoto: 10 złota = 5000 / procent ceny
    expect($buy(1, 0))->toBe(50)
        ->and($buy(2, 0))->toBe(52)
        ->and($buy(5, 0))->toBe(55)
        ->and($buy(5, 40))->toBe(61);
});

it('sprzedaż statku i mapa akwenu idą za tym samym mnożnikiem', function () {
    $offer = (new MistSeaIslandCatalog())->tradePort()->chart ?? throw new LogicException('Mist port sells a chart');
    $rate = (new StaticIslandCatalog())->tradePort()->rate;
    $ship = OwnedShip::build(ShipType::Niszczyciel);
    $base = tradesman(1);
    $master = tradesman(5);

    // 150 × 95% = 142,5 → 143 (w górę jak dotąd), 150 × 90% = 135
    expect($base->chartPrice($offer))->toBe(150)
        ->and(tradesman(2)->chartPrice($offer))->toBe(143)
        ->and($master->chartPrice($offer))->toBe(135)
        ->and($master->shipSaleValue($ship, $rate))->toBe($rate->goldValue($master->scrapValue($ship), 90))
        ->and($master->shipSaleValue($ship, $rate))->toBeGreaterThanOrEqual($base->shipSaleValue($ship, $rate));
});

it('handlarz na morzu: dopłata 25% do poziomu 4, od poziomu 5 ceny jak w porcie', function () {
    $rate = (new StaticIslandCatalog())->tradePort()->rate;

    expect(tradesman(1)->merchantSurchargePercent())->toBe(25)
        ->and(tradesman(1)->merchantPrices($rate)->pricePercent)->toBe(125)
        // 95 × 125 / 100 = 118,75
        ->and(tradesman(4)->merchantPrices($rate)->pricePercent)->toBe(118)
        ->and(tradesman(5)->merchantSurchargePercent())->toBe(0)
        ->and(tradesman(5)->merchantPrices($rate)->pricePercent)->toBe(90)
        // kupiec z perkiem: 90 × 90 / 100
        ->and(tradesman(5, reputation: 40)->merchantPrices($rate)->pricePercent)->toBe(81);
});

it('zlecenie u handlarza bez dopłaty kupuje tyle co w porcie', function () {
    $rate = (new StaticIslandCatalog())->tradePort()->rate;
    $encounter = new Encounter(EncounterType::Merchant, 10, Sea::START, 0, 0);
    $wood = new Resources(wood: 50);
    $profile = tradesman(5, [OwnedShip::build(ShipType::Transportowiec)], new Resources(gold: 10), extra: ['encounter' => $encounter]);

    // 50 drewna: poziom 4 (118%) kupuje 4 sztuki za złoto → 13 złota; poziom 5 (90%) jak w porcie 5 → 10 złota
    expect(tradesman(4)->merchantPrices($rate)->buyCost($wood))->toBe(13)
        ->and($profile->tradeWithMerchant(Resources::none(), $wood, $rate)['goldPaid'])->toBe(10)
        ->and($profile->cargo()->toArray())->toBe(['wood' => 50, 'steel' => 0, 'gold' => 0]);
});

it('budowa statków −10% od poziomu 3, z magazynu wyspy', function () {
    expect(tradesman(2)->shipBuildCost(ShipType::Niszczyciel)->toArray())->toBe(['wood' => 30, 'steel' => 20, 'gold' => 0])
        ->and(tradesman(3)->shipBuildCost(ShipType::Niszczyciel)->toArray())->toBe(['wood' => 27, 'steel' => 18, 'gold' => 0])
        ->and(tradesman(3)->shipBuildCost(ShipType::Tratwa)->isZero())->toBeTrue();

    $profile = tradesman(3, extra: ['stockpiles' => ['zatoka-rozbitka' => new Resources(wood: 20)]]);
    $ship = $profile->buildShip(ShipType::Kuter, 'zatoka-rozbitka');

    // kuter 20 drewna → 18; złom dalej od kosztu typu
    expect($profile->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 2, 'steel' => 0, 'gold' => 0])
        ->and($profile->scrapValue($ship)->toArray())->toBe(['wood' => 10, 'steel' => 0, 'gold' => 0]);
});

it('magazyn złóż +5 tików od poziomu 4, dodane do Spichlerzy', function () {
    $mielizny = perkIsland('mielizny');
    $perTick = $mielizny->depositYield->wood;

    expect(tradesman(3)->depositCap($mielizny)->wood)->toBe($perTick * 10)
        ->and(tradesman(4)->depositCap($mielizny)->wood)->toBe($perTick * 15)
        ->and(tradesman(3, researched: [Technology::Spichlerze])->depositCap($mielizny)->wood)->toBe($perTick * 20)
        ->and(tradesman(4, researched: [Technology::Spichlerze])->depositCap($mielizny)->wood)->toBe($perTick * 25);
});

it('złoże odkłada do magazynu do wyższego limitu', function () {
    $mielizny = perkIsland('mielizny');
    $accrue = static function (int $level): int {
        $profile = tradesman($level, extra: ['tick' => 100, 'depositTicks' => ['mielizny' => 0], 'ownedIslands' => ['mielizny' => 0]]);
        $islands = (new StaticIslandCatalog())->all();
        $profile->ensureWorldState(WorldMap::generate($profile->worldSeed(), array_map(static fn ($i) => $i->id, $islands), $islands));

        return $profile->stockpile('mielizny')->wood;
    };

    expect($accrue(3))->toBe($mielizny->depositYield->wood * 10)
        ->and($accrue(4))->toBe($mielizny->depositYield->wood * 15);
});

it('perki działają po zapisie i odczycie profilu', function () {
    $mapper = new ProfileSnapshotMapper();
    $saved = tradesman(5);
    $profile = $mapper->toDomain((string) $saved->id(), $mapper->toArray($saved));

    expect($profile->marketPricePercent())->toBe(90)
        ->and($profile->cargoCapacity())->toBe(38);
});
