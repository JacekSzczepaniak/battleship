<?php

declare(strict_types=1);

use App\Domain\Expedition\BattleKind;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

const COUNTERATTACK_SEED = 7;

function counterattackSea(int $seed = COUNTERATTACK_SEED): WorldMap
{
    $islands = (new StaticIslandCatalog())->all();

    return WorldMap::generate($seed, array_map(static fn ($i) => $i->id, $islands), $islands);
}

/**
 * Kapitan zakotwiczony na Wyspie Sygnałowej (bez złoża), z odwiedzonym
 * sąsiednim sektorem — rejsy tam i z powrotem odmierzają tiki bez sztormów.
 *
 * @param array<string, int>       $owned
 * @param array<string, Resources> $stockpiles
 */
function captainWithFlags(array $owned, array $stockpiles = [], int $tick = 0, ?array $counterattacks = null): CaptainProfile
{
    $world = counterattackSea();
    $home = $world->islandPosition('wyspa-sygnalowa');
    $away = shuttleSector($world);

    return CaptainProfile::fromSnapshot(
        ProfileId::new(), 'Jacek', 0, [], null, Resources::none(), COUNTERATTACK_SEED, $home,
        ["{$home['x']}:{$home['y']}"], 0, $tick, ["{$home['x']}:{$home['y']}", "{$away['x']}:{$away['y']}"],
        $stockpiles, null, [], $owned, $counterattacks,
    );
}

/** @return array{x:int,y:int} sąsiad Wyspy Sygnałowej w granicach mapy */
function shuttleSector(WorldMap $world): array
{
    $home = $world->islandPosition('wyspa-sygnalowa');

    return $world->isInside($home['x'] + 1, $home['y']) ? ['x' => $home['x'] + 1, 'y' => $home['y']] : ['x' => $home['x'] - 1, 'y' => $home['y']];
}

/** Jeden tik: rejs między wyspą a sąsiednim sektorem, potem naliczenie świata. */
function passTick(CaptainProfile $profile): void
{
    $world = counterattackSea();
    $home = $world->islandPosition('wyspa-sygnalowa');
    $target = $profile->position() === $home ? shuttleSector($world) : $home;
    $profile->sail($target['x'], $target['y'], $world);
    $profile->ensureWorldState($world);
}

/** Odmierza tiki, aż pojawi się zapowiedź kontrataku (z bezpiecznikiem). */
function awaitCounterattack(CaptainProfile $profile): array
{
    for ($i = 0; $i < 300 && null === $profile->counterattack(); ++$i) {
        passTick($profile);
    }

    return $profile->counterattack() ?? throw new LogicException('No counterattack within 300 ticks');
}

/** @return list<array{0:string,1:bool}> */
function fleetState(CaptainProfile $profile): array
{
    return array_map(static fn ($s) => [$s->id, $s->isDamaged()], $profile->fleet());
}

it('ten sam seed i ta sama sekwencja akcji dają te same kontrataki', function () {
    $owned = ['wyspa-sygnalowa' => 0, 'mielizny' => 0, 'zatoka-rozbitka' => 0];
    $log = static function (CaptainProfile $profile): array {
        $out = [];
        foreach (range(1, 80) as $ignored) {
            passTick($profile);
            $out[] = [$profile->tick(), $profile->counterattack(), $profile->lastCounterattack(), $profile->ownedIslands()];
        }

        return $out;
    };

    $first = $log(captainWithFlags($owned));
    $second = $log(captainWithFlags($owned));

    expect($first)->toBe($second)
        ->and(array_filter(array_column($first, 1)))->not->toBe([]);
});

it('zapowiedź po 8 tikach spokoju, nigdy dwie naraz, nigdy w Zatokę Rozbitka', function () {
    $profile = captainWithFlags(['zatoka-rozbitka' => 0, 'mielizny' => 0, 'wyspa-sygnalowa' => 0, 'archipelag-mgiel' => 0]);
    $calmFrom = 0;
    $announcements = [];

    foreach (range(1, 300) as $ignored) {
        $before = $profile->counterattack();
        passTick($profile);
        $now = $profile->counterattack();
        if (null !== $before && null !== $now) {
            // kontratak w toku trwa do rozstrzygnięcia — drugi nie wchodzi w jego miejsce
            expect($now)->toBe($before);
        }
        if (null === $before && null !== $now) {
            expect($now['announcedAt'])->toBeGreaterThan($calmFrom + 8)
                ->and($now['strikeAt'])->toBe($now['announcedAt'] + 12)
                ->and($now['island'])->not->toBe('zatoka-rozbitka');
            $announcements[] = $now['island'];
        }
        $calmFrom = $profile->lastCounterattack()['tick'] ?? $calmFrom;
    }

    // nieobecność odebrała trzy wyspy po kolei; przystań zostaje i nie ściąga kontrataków
    expect($announcements)->toHaveCount(3)
        ->and($profile->ownedIslands())->toBe(['zatoka-rozbitka' => 0])
        ->and($profile->counterattack())->toBeNull();
});

it('spokój po zdobyciu wyspy: zapowiedź nie wcześniej niż 8 tików po zdobyciu', function () {
    $profile = captainWithFlags([]);
    passTick($profile);
    passTick($profile);
    $gameId = GameId::new();
    $profile->startBattle('wyspa-sygnalowa', $gameId);
    $profile->settleBattle($gameId, 'won', 70);
    $capturedAt = $profile->tick();

    expect(awaitCounterattack($profile)['announcedAt'])->toBeGreaterThan($capturedAt + 8);
});

it('nieobecność do końca okna odbiera wyspę i połowę magazynu, bez strat we flocie', function () {
    $profile = captainWithFlags(['wyspa-sygnalowa' => 0], ['wyspa-sygnalowa' => new Resources(21, 7, 3)]);
    $fleet = fleetState($profile);
    $counterattack = awaitCounterattack($profile);

    while ($profile->tick() < $counterattack['strikeAt'] - 1) {
        passTick($profile);
    }
    expect($profile->ownsIsland('wyspa-sygnalowa'))->toBeTrue();

    passTick($profile);

    expect($profile->ownsIsland('wyspa-sygnalowa'))->toBeFalse()
        ->and($profile->counterattack())->toBeNull()
        ->and($profile->lastCounterattack())->toBe([
            'island' => 'wyspa-sygnalowa', 'tick' => $counterattack['strikeAt'], 'held' => false, 'absent' => true, 'plundered' => false,
            'stockpileLost' => ['wood' => 10, 'steel' => 3, 'gold' => 1],
        ])
        ->and($profile->stockpile('wyspa-sygnalowa'))->toEqual(new Resources(11, 4, 2))
        ->and(fleetState($profile))->toBe($fleet)
        // dziennik (#111): zapowiedź i utrata w tikach zdarzeń, nie w tiku odczytu
        ->and(array_map(static fn (LogEntry $e) => [$e->tick, $e->event, $e->data], $profile->log()))->toBe([
            [$counterattack['announcedAt'], LogEvent::CounterattackAnnounced, ['island' => 'wyspa-sygnalowa', 'strikeAt' => $counterattack['strikeAt']]],
            [$counterattack['strikeAt'], LogEvent::IslandLost, ['island' => 'wyspa-sygnalowa', 'absent' => true, 'stockpileLost' => ['wood' => 10, 'steel' => 3, 'gold' => 1]]],
        ]);
});

it('utrata wyspy zatrzymuje złoże — urobek liczy się do tiku uderzenia', function () {
    $profile = captainWithFlags(['mielizny' => 0]);
    $counterattack = awaitCounterattack($profile);
    while ($profile->tick() < $counterattack['strikeAt']) {
        passTick($profile);
    }
    $afterLoss = $profile->stockpile('mielizny');
    foreach (range(1, 5) as $ignored) {
        passTick($profile);
    }

    // uderzenie najwcześniej w tiku 21: złoże (3 drewna/tik) zdążyło dobić do limitu 30, połowa przepadła
    expect($afterLoss)->toEqual(new Resources(wood: 15))
        ->and($profile->stockpile('mielizny'))->toEqual($afterLoss)
        ->and($profile->depositTicks())->not->toHaveKey('mielizny');
});

it('rozpoczęta obrona wstrzymuje termin, a wygrana zostawia banderę i zaczyna spokój od nowa', function () {
    $profile = captainWithFlags(['wyspa-sygnalowa' => 0]);
    $counterattack = awaitCounterattack($profile);
    $gameId = GameId::new();
    $profile->startDefense('wyspa-sygnalowa', $gameId, [$profile->fleet()[0]->id]);

    expect($profile->battles()[(string) $gameId]['kind'])->toBe(BattleKind::Defense->value);

    while ($profile->tick() <= $counterattack['strikeAt'] + 2) {
        passTick($profile);
    }
    expect($profile->ownsIsland('wyspa-sygnalowa'))->toBeTrue()
        ->and($profile->counterattack()['defense'] ?? null)->toBe((string) $gameId);

    $outcome = $profile->settleBattle($gameId, 'won', 70, new Resources(20, 10, 5));
    $settledAt = $profile->tick();

    expect($outcome['captured'])->toBeFalse()
        ->and($outcome['defense'])->toEqual(['held' => true, 'stockpileLost' => Resources::none()])
        ->and($outcome['xp'])->toBe(70)
        ->and($profile->ownsIsland('wyspa-sygnalowa'))->toBeTrue()
        ->and($profile->stockpile('wyspa-sygnalowa'))->toEqual(new Resources(20, 10, 5))
        ->and($profile->counterattack())->toBeNull()
        ->and($profile->lastCounterattack()['held'] ?? null)->toBeTrue()
        ->and(array_slice($profile->log(), -1)[0] ?? null)->toEqual(new LogEntry($settledAt, LogEvent::IslandDefended, ['island' => 'wyspa-sygnalowa']))
        ->and(awaitCounterattack($profile)['announcedAt'])->toBeGreaterThan($settledAt + 8);
});

it('przegrana obrona odbiera wyspę i połowę magazynu, łup za porażkę trafia do magazynu, straty jak w bitwie', function () {
    $profile = captainWithFlags(['wyspa-sygnalowa' => 0], ['wyspa-sygnalowa' => new Resources(20, 10, 4)]);
    awaitCounterattack($profile);
    $kuter = $profile->fleet()[0];
    $gameId = GameId::new();
    $profile->startDefense('wyspa-sygnalowa', $gameId, [$kuter->id]);

    $outcome = $profile->settleBattle($gameId, 'lost', 15, new Resources(wood: 8), [$kuter->type->length()]);

    expect($outcome['defense'])->toEqual(['held' => false, 'stockpileLost' => new Resources(10, 5, 2)])
        ->and($outcome['lost'])->toBe([$kuter->type->value])
        ->and($profile->ownsIsland('wyspa-sygnalowa'))->toBeFalse()
        ->and($profile->stockpile('wyspa-sygnalowa'))->toEqual(new Resources(18, 5, 2))
        ->and($profile->lastCounterattack()['absent'] ?? null)->toBeFalse()
        ->and($profile->battleStats('wyspa-sygnalowa'))->toBe(['wins' => 0, 'losses' => 1]);
});

it('obrony nie da się przyjąć bez kontrataku na tej wyspie', function () {
    captainWithFlags(['wyspa-sygnalowa' => 0])->startDefense('wyspa-sygnalowa', GameId::new());
})->throws(DomainException::class, 'No counterattack on this island');

it('jedna obrona na kontratak', function () {
    $profile = captainWithFlags(['wyspa-sygnalowa' => 0]);
    awaitCounterattack($profile);
    $profile->startDefense('wyspa-sygnalowa', GameId::new());
    $profile->startDefense('wyspa-sygnalowa', GameId::new());
})->throws(DomainException::class, 'Defense already started');

it('stan kontrataku przeżywa zapis i odczyt profilu', function () {
    $m = new ProfileSnapshotMapper();
    $profile = captainWithFlags(['wyspa-sygnalowa' => 0, 'mielizny' => 0]);
    awaitCounterattack($profile);
    $profile->startDefense($profile->counterattack()['island'], GameId::new());

    $restored = $m->toDomain((string) $profile->id(), $m->toArray($profile));

    expect($restored->counterattackState())->toBe($profile->counterattackState());
});

it('profil sprzed kontrataków nie losuje zapowiedzi wstecz', function () {
    $profile = captainWithFlags(['wyspa-sygnalowa' => 0], tick: 200);
    $profile->ensureWorldState(counterattackSea());

    expect($profile->counterattack())->toBeNull()
        ->and($profile->counterattackState()['rolledTo'])->toBe(200)
        ->and($profile->counterattackState()['calmFrom'])->toBe(0);
});
