<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Encounter;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\MarketGood;
use App\Domain\Expedition\MarketSide;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Perk;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\Specializations;
use App\Domain\Expedition\SpecializationSource;
use App\Domain\Expedition\SpecializationTrack;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// Specjalizacje kapitana (#160, Epic K): punkty z akcji (D3), progi (D2), dziennik (D5), zera w starych profilach (D7).

/**
 * Kapitan z dużą ładownią (2 transportowce = 120), surowcami w magazynach wysp
 * i opcjonalnie liczników specjalizacji.
 *
 * @param array<string, Resources> $stockpiles
 * @param array<string, mixed>     $extra      dodatkowe argumenty fromSnapshot
 */
function specCaptain(array $stockpiles = [], ?Resources $cargo = null, ?Specializations $specializations = null, array $extra = []): CaptainProfile
{
    return CaptainProfile::fromSnapshot(...[
        'id' => CaptainProfile::create('Jacek')->id(),
        'name' => 'Jacek',
        'xp' => 0,
        'battles' => [],
        'fleet' => [OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Transportowiec)],
        'cargo' => $cargo ?? Resources::none(),
        'tick' => 10,
        'stockpiles' => $stockpiles,
        'specializations' => $specializations,
    ] + $extra);
}

/** Mapa świata z wyspami i złożami z katalogu — jak WorldFactory. */
function specWorld(CaptainProfile $profile): WorldMap
{
    $islands = (new StaticIslandCatalog())->all();

    return WorldMap::generate($profile->worldSeed(), array_map(static fn ($i) => $i->id, $islands), $islands);
}

/**
 * Kapitan z Mieliznami (złoże: 3 drewna/tik) pod banderą.
 *
 * @param array<string,int> $depositTicks
 */
function specMiner(int $tick, array $depositTicks): CaptainProfile
{
    return CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 40, [], null, Resources::none(),
        tick: $tick, depositTicks: $depositTicks, ownedIslands: ['mielizny' => 0],
    );
}

/** Rozlicza bitwę danego rodzaju od startu do wyniku. */
function specBattle(CaptainProfile $profile, string $kind, string $result, int $sunk = 0, bool $boarded = false): void
{
    $gameId = GameId::new();
    match ($kind) {
        'capture' => $profile->startBattle('mielizny', $gameId),
        'siege' => $profile->startSiege('twierdza', $gameId),
        'defense' => $profile->startDefense('mielizny', $gameId),
        'guardian' => $profile->startGuardianBattle('brama', $gameId),
    };
    $profile->settleBattle($gameId, $result, 0, enemyShipsSunk: $sunk, boardedType: $boarded ? ShipType::Kuter : null);
}

function specUnits(CaptainProfile $profile, SpecializationSource $source): int
{
    return $profile->specializations()->units($source);
}

/** @return list<array<string, mixed>> dane wpisów o awansie */
function specLevelUps(CaptainProfile $profile): array
{
    return array_values(array_map(
        static fn (LogEntry $e) => $e->data,
        array_filter($profile->log(), static fn (LogEntry $e) => LogEvent::SpecializationLevel === $e->event),
    ));
}

it('progi 20, 60, 120, 200 i 300 dają poziomy 1–5', function (int $points, int $level) {
    expect(SpecializationTrack::levelFor($points))->toBe($level);
})->with([
    [0, 0], [19, 0], [20, 1], [59, 1], [60, 2], [119, 2], [120, 3], [200, 4], [299, 4], [300, 5], [999, 5],
]);

it('punkty liczą się z jednostek akcji, a reszta z dzielenia nie przepada', function () {
    $spec = (new Specializations())
        ->add(SpecializationSource::MarketTurnover, 13)
        ->add(SpecializationSource::MarketTurnover, 13)
        ->add(SpecializationSource::Transport, 19)
        ->add(SpecializationSource::Mining, 59)
        ->add(SpecializationSource::Discovery, 3);

    expect($spec->units(SpecializationSource::MarketTurnover))->toBe(26)
        ->and($spec->points(SpecializationTrack::Economy))->toBe(1 + 0 + 1 + 3)
        ->and($spec->add(SpecializationSource::Transport, 1)->points(SpecializationTrack::Economy))->toBe(6)
        ->and($spec->points(SpecializationTrack::Combat))->toBe(0);
});

it('wygrana daje +5, a każdy zatopiony wróg +1 — także w przegranej', function () {
    $profile = specCaptain();

    specBattle($profile, 'capture', 'won', sunk: 3);
    expect($profile->specializations()->points(SpecializationTrack::Combat))->toBe(8);

    specBattle($profile, 'guardian', 'lost', sunk: 2);
    expect($profile->specializations()->points(SpecializationTrack::Combat))->toBe(10)
        ->and(specUnits($profile, SpecializationSource::Win))->toBe(1);
});

it('abordaż, obrona i oblężenie dokładają premię do wygranej', function () {
    $boarding = specCaptain();
    specBattle($boarding, 'capture', 'won', boarded: true);

    $defense = specCaptain(extra: [
        'ownedIslands' => ['mielizny' => 0],
        'counterattacks' => ['pending' => ['island' => 'mielizny', 'announcedAt' => 5, 'strikeAt' => 50, 'defense' => null], 'calmFrom' => 0, 'rolledTo' => 10, 'last' => null],
    ]);
    specBattle($defense, 'defense', 'won');

    $siege = specCaptain(extra: ['besieged' => ['twierdza' => 3]]);
    specBattle($siege, 'siege', 'won');

    expect($boarding->specializations()->points(SpecializationTrack::Combat))->toBe(10)
        ->and($defense->specializations()->points(SpecializationTrack::Combat))->toBe(10)
        ->and($siege->specializations()->points(SpecializationTrack::Combat))->toBe(15);
});

it('przegrane oblężenie nie daje ani wygranej, ani premii', function () {
    $siege = specCaptain(extra: ['besieged' => ['twierdza' => 3]]);
    specBattle($siege, 'siege', 'lost');

    expect($siege->specializations()->points(SpecializationTrack::Combat))->toBe(0);
});

it('drugie rozliczenie tej samej bitwy nie nalicza punktów', function () {
    $profile = specCaptain();
    $gameId = GameId::new();
    $profile->startBattle('mielizny', $gameId);

    $profile->settleBattle($gameId, 'won', 0, enemyShipsSunk: 2);
    $profile->settleBattle($gameId, 'won', 0, enemyShipsSunk: 2);

    expect($profile->specializations()->points(SpecializationTrack::Combat))->toBe(7);
});

it('obrót na rynku portu liczy złoto transakcji, ułamki kumulują się', function () {
    $port = (new StaticIslandCatalog())->tradePort();
    $profile = specCaptain([$port->id => new Resources(wood: 1000)]);

    $profile->trade($port, MarketSide::Sell, MarketGood::Wood, 13);
    expect(specUnits($profile, SpecializationSource::MarketTurnover))->toBe(13)
        ->and($profile->specializations()->points(SpecializationTrack::Economy))->toBe(0);

    $profile->trade($port, MarketSide::Buy, MarketGood::Wood, 7);
    expect($profile->specializations()->points(SpecializationTrack::Economy))->toBe(1);
});

it('handel z handlarzem liczy złoto otrzymane i zapłacone', function () {
    $port = (new StaticIslandCatalog())->tradePort();
    $profile = specCaptain(cargo: new Resources(wood: 60), extra: ['encounter' => new Encounter(EncounterType::Merchant, 10, Sea::START, 0, 0)]);

    $outcome = $profile->tradeWithMerchant(new Resources(wood: 60), new Resources(steel: 2), $port->rate);

    expect(specUnits($profile, SpecializationSource::MarketTurnover))->toBe($outcome['goldReceived'] + $outcome['goldPaid'])
        ->and($outcome['goldReceived'])->toBeGreaterThan(0);
});

it('ładunek liczy się tylko po rozładunku na innej wyspie niż załadunek', function () {
    $profile = specCaptain(['a' => new Resources(wood: 100), 'b' => new Resources(steel: 50)]);

    $profile->loadCargo('a', new Resources(wood: 50));
    $profile->unloadCargo('a', new Resources(wood: 10));
    expect(specUnits($profile, SpecializationSource::Transport))->toBe(0)
        ->and($profile->cargoOrigins())->toBe(['a' => 40]);

    $profile->unloadCargo('b', new Resources(wood: 30));
    expect(specUnits($profile, SpecializationSource::Transport))->toBe(30)
        ->and($profile->specializations()->points(SpecializationTrack::Economy))->toBe(1)
        ->and($profile->cargoOrigins())->toBe(['a' => 10]);

    // z b na a: 10 drewna z a wraca do domu, 20 stali z b to transport
    $profile->loadCargo('b', new Resources(steel: 20));
    $profile->unloadCargo('a', new Resources(wood: 10, steel: 20));
    expect(specUnits($profile, SpecializationSource::Transport))->toBe(50)
        ->and($profile->cargoOrigins())->toBe([]);
});

it('ładunek bez pochodzenia (łup, kartografia) nie jest transportem', function () {
    $profile = specCaptain(cargo: new Resources(wood: 40));

    $profile->unloadCargo('b', new Resources(wood: 40));

    expect(specUnits($profile, SpecializationSource::Transport))->toBe(0);
});

it('straty ładowni zabierają najpierw ładunek bez pochodzenia', function () {
    // 30 drewna z łupu w ładowni, 20 załadowane na a
    $profile = specCaptain(['a' => new Resources(wood: 20), 'port' => Resources::none()], new Resources(wood: 30));
    $profile->loadCargo('a', new Resources(wood: 20));

    // handel w porcie płaci z ładowni 40 drewna: łup przepada w całości, z a zostaje 10
    $port = (new StaticIslandCatalog())->tradePort();
    $profile->trade($port, MarketSide::Sell, MarketGood::Wood, 4);

    expect($profile->cargo()->wood)->toBe(10)
        ->and($profile->cargoOrigins())->toBe(['a' => 10]);
});

it('urobek złoża odłożony w magazynie liczy się do ścieżki ekonomicznej', function () {
    $profile = specMiner(tick: 7, depositTicks: ['mielizny' => 3]);
    $profile->ensureWorldState(specWorld($profile));
    expect(specUnits($profile, SpecializationSource::Mining))->toBe(12);

    // limit magazynu: złoże staje, a stojące nie daje punktów
    $full = specMiner(tick: 100, depositTicks: ['mielizny' => 0]);
    $full->ensureWorldState(specWorld($full));
    expect(specUnits($full, SpecializationSource::Mining))->toBe(30)
        ->and($full->specializations()->points(SpecializationTrack::Economy))->toBe(1);
});

it('każdy nowo odkryty sektor daje punkt, start na morzu nie', function () {
    $profile = CaptainProfile::create('Jacek');
    $profile->ensureWorldState($world = specWorld($profile));
    expect(specUnits($profile, SpecializationSource::Discovery))->toBe(0);

    $at = $profile->position();
    $x = $at['x'] + 1 < WorldMap::WIDTH ? $at['x'] + 1 : $at['x'] - 1;
    $outcome = $profile->sail($x, $at['y'], $world);

    expect($outcome['discoveredNow'])->not->toBeEmpty()
        ->and(specUnits($profile, SpecializationSource::Discovery))->toBe(count($outcome['discoveredNow']));
});

it('awans trafia do dziennika osobno na każdy przekroczony próg', function () {
    $profile = specCaptain(specializations: (new Specializations())->add(SpecializationSource::Win, 3));
    expect($profile->specializationLevel(SpecializationTrack::Combat))->toBe(0);

    // 15 + 5 za wygraną + 45 zatopionych = 65 → poziom 2 jedną bitwą
    specBattle($profile, 'capture', 'won', sunk: 45);

    expect($profile->specializationLevel(SpecializationTrack::Combat))->toBe(2)
        ->and(specLevelUps($profile))->toBe([['track' => 'combat', 'level' => 1], ['track' => 'combat', 'level' => 2]]);
});

it('perk działa od swojego poziomu, ścieżki są niezależne', function () {
    $profile = specCaptain(specializations: (new Specializations())->add(SpecializationSource::Win, 12));

    expect($profile->specializationLevel(SpecializationTrack::Combat))->toBe(2)
        ->and($profile->hasPerk(Perk::RepairDiscount))->toBeTrue()
        ->and($profile->hasPerk(Perk::BoardingEdge))->toBeTrue()
        ->and($profile->hasPerk(Perk::ExtraSonar))->toBeFalse()
        ->and($profile->hasPerk(Perk::LargerHold))->toBeFalse()
        ->and($profile->perks())->toBe([Perk::RepairDiscount, Perk::BoardingEdge]);
});

it('katalog ma po jednym perku na poziom każdej ścieżki', function () {
    foreach (SpecializationTrack::cases() as $track) {
        expect(array_map(static fn (Perk $p) => $p->level(), $track->perks()))->toBe([1, 2, 3, 4, 5]);
    }
    expect(SpecializationTrack::Combat->perks()[0])->toBe(Perk::RepairDiscount)
        ->and(SpecializationTrack::Economy->perks()[4])->toBe(Perk::MasterTrader);
});

it('snapshot zachowuje liczniki i pochodzenie ładunku, a stary profil startuje od zera', function () {
    $mapper = new ProfileSnapshotMapper();
    $profile = specCaptain(['a' => new Resources(wood: 30)], specializations: (new Specializations())->add(SpecializationSource::Transport, 15));
    $profile->loadCargo('a', new Resources(wood: 30));

    $restored = $mapper->toDomain((string) $profile->id(), $mapper->toArray($profile));
    $state = $mapper->toArray($profile);
    unset($state['specializations'], $state['cargoOrigins']);
    $legacy = $mapper->toDomain((string) $profile->id(), $state);

    expect($restored->specializations()->toArray())->toBe($profile->specializations()->toArray())
        ->and($restored->cargoOrigins())->toBe(['a' => 30])
        ->and($legacy->specializations()->points(SpecializationTrack::Economy))->toBe(0)
        ->and($legacy->cargoOrigins())->toBe([]);
});
