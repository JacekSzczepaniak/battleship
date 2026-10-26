<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Encounter;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// „Zmień skład” (#166): anulowanie nierozstawionej bitwy wyprawy.

const CANCEL_SEED = 7;

function cancelSea(): WorldMap
{
    $graph = new StaticSeaGraph(new StaticIslandCatalog());

    return $graph->sea(Sea::START)->map(CANCEL_SEED, $graph->passagesFrom(Sea::START));
}

/** Kapitan na Wyspie Sygnałowej pod banderą, opcjonalnie z kontratakiem w toku. */
function cancelCaptain(int $tick, ?array $pending = null): CaptainProfile
{
    $home = cancelSea()->islandPosition('wyspa-sygnalowa');

    return CaptainProfile::fromSnapshot(
        ProfileId::new(), 'Jacek', 0, [], null, Resources::none(), CANCEL_SEED, $home,
        ["{$home['x']}:{$home['y']}"], 0, $tick, null, [], null, [], ['wyspa-sygnalowa' => 0],
        ['pending' => $pending, 'calmFrom' => 0, 'rolledTo' => $tick, 'last' => null],
    );
}

/** Kapitan na otwartym morzu ze spotkaniem do decyzji. */
function cancelEncounterCaptain(EncounterType $type, int $reputation = 0): CaptainProfile
{
    $world = cancelSea();
    for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
        for ($x = 0; $x < WorldMap::WIDTH; ++$x) {
            if (null === $world->islandAt($x, $y) && null === $world->gateAt($x, $y)) {
                break 2;
            }
        }
    }

    return CaptainProfile::fromSnapshot(
        ProfileId::new(), 'Jacek', 0, [], [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Tratwa)],
        Resources::none(), CANCEL_SEED, ['x' => $x, 'y' => $y], ["$x:$y"],
        tick: 4,
        encounter: new Encounter($type, 3, Sea::START, $x, $y),
        reputation: $reputation,
    );
}

it('anulowana bitwa o wyspę znika z rejestru, statki wracają, a tik przepada', function () {
    $profile = cancelCaptain(10);
    $ships = array_map(static fn (OwnedShip $s) => $s->id, $profile->activeFleet());
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId, $ships);

    expect($profile->scrapBlocker($profile->activeFleet()[0]))->not->toBeNull();

    expect($profile->cancelBattle($gameId, cancelSea()))->toBe($ships)
        ->and($profile->battles())->toBe([])
        ->and($profile->islandFor($gameId))->toBeNull()
        ->and($profile->tick())->toBe(11)
        ->and($profile->scrapBlocker($profile->activeFleet()[0]))->toBeNull();

    // nowa bitwa z innym składem kosztuje kolejny tik
    $profile->startBattle('zatoka-rozbitka', GameId::new(), [$ships[0]]);
    expect($profile->tick())->toBe(12);
});

it('anulowana obrona przed uderzeniem pozwala podjąć obronę ponownie', function () {
    $profile = cancelCaptain(25, ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null]);
    $gameId = GameId::new();
    $profile->startDefense('wyspa-sygnalowa', $gameId);
    expect($profile->counterattack()['defense'])->toBe((string) $gameId);

    $profile->cancelBattle($gameId, cancelSea());

    expect($profile->counterattack())->toBe(['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null])
        ->and($profile->ownsIsland('wyspa-sygnalowa'))->toBeTrue()
        ->and($profile->tick())->toBe(26);

    $again = GameId::new();
    $profile->startDefense('wyspa-sygnalowa', $again);
    expect($profile->counterattack()['defense'])->toBe((string) $again);
});

it('anulowana obrona po terminie uderzenia rozstrzyga kontratak od razu jak nieobecność', function () {
    // start obrony w tiku 31 przesuwa czas do 32 — termin uderzenia
    $profile = cancelCaptain(31, ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null]);
    $gameId = GameId::new();
    $profile->startDefense('wyspa-sygnalowa', $gameId);

    $profile->cancelBattle($gameId, cancelSea());

    expect($profile->counterattack())->toBeNull()
        ->and($profile->ownsIsland('wyspa-sygnalowa'))->toBeFalse()
        ->and($profile->lastCounterattack())->toMatchArray(['island' => 'wyspa-sygnalowa', 'tick' => 32, 'held' => false, 'absent' => true]);
    $events = array_map(static fn ($e) => $e->event, $profile->log());
    expect($events)->toContain(LogEvent::IslandLost);
});

it('anulowany napad przywraca handlarza do decyzji i zwraca karę reputacji', function () {
    $profile = cancelEncounterCaptain(EncounterType::Merchant, reputation: 10);
    $before = $profile->encounter()->toArray();
    $gameId = GameId::new();
    $profile->startEncounterBattle($gameId);
    expect($profile->encounter())->toBeNull()
        ->and($profile->reputation()->value)->toBe(-10);

    $profile->cancelBattle($gameId, cancelSea());

    expect($profile->encounter()?->toArray())->toBe($before)
        ->and($profile->reputation()->value)->toBe(10)
        ->and($profile->tick())->toBe(5);
});

it('kara za napad przy granicy osi zwraca tylko to, co faktycznie zabrała', function () {
    $profile = cancelEncounterCaptain(EncounterType::Merchant, reputation: -90);
    $gameId = GameId::new();
    $profile->startEncounterBattle($gameId);
    expect($profile->reputation()->value)->toBe(-100);

    $profile->cancelBattle($gameId, cancelSea());
    expect($profile->reputation()->value)->toBe(-90);
});

it('anulowana bitwa z piratem przywraca spotkanie bez zmiany reputacji', function () {
    $profile = cancelEncounterCaptain(EncounterType::Pirate);
    $gameId = GameId::new();
    $profile->startEncounterBattle($gameId);
    $profile->cancelBattle($gameId, cancelSea());

    expect($profile->encounter()?->type)->toBe(EncounterType::Pirate)
        ->and($profile->reputation()->value)->toBe(0);
});

it('anulowanie rozliczonej albo obcej bitwy to błąd domenowy', function () {
    $profile = cancelCaptain(10);
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'lost', 0);

    expect(fn () => $profile->cancelBattle($gameId, cancelSea()))->toThrow(DomainException::class, 'Battle already settled')
        ->and(fn () => $profile->cancelBattle(GameId::new(), cancelSea()))->toThrow(DomainException::class, 'Battle not registered for this profile');
});

it('miejsce spotkania i kara za napad przeżywają zapis profilu', function () {
    $mapper = new ProfileSnapshotMapper();
    $profile = cancelEncounterCaptain(EncounterType::Merchant, reputation: 10);
    $before = $profile->encounter()->toArray();
    $gameId = GameId::new();
    $profile->startEncounterBattle($gameId);

    $restored = $mapper->toDomain((string) $profile->id(), json_decode(json_encode($mapper->toArray($profile)), true));
    $restored->cancelBattle($gameId, cancelSea());

    expect($restored->encounter()?->toArray())->toBe($before)
        ->and($restored->reputation()->value)->toBe(10);
});

it('anulowane oblężenie nie liczy się jako próba — nikt nie widział fortu przed pierwszym strzałem', function () {
    $profile = cancelCaptain(10);
    $skirmish = GameId::new();
    $profile->startBattle('twierdza-admiralicji', $skirmish, stronghold: true);
    $profile->settleBattle($skirmish, 'won', 30);

    $siege = GameId::new();
    $profile->startSiege('twierdza-admiralicji', $siege);
    expect($profile->siegeAttempts('twierdza-admiralicji'))->toBe(1);

    $profile->cancelBattle($siege, cancelSea());
    expect($profile->siegeAttempts('twierdza-admiralicji'))->toBe(0)
        ->and($profile->isBesieged('twierdza-admiralicji'))->toBeTrue();
});
