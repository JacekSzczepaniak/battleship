<?php

declare(strict_types=1);

use App\Domain\Expedition\BattleKind;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

/** @return array{xp:int, loot:Resources, lost:list<string>, damaged:list<string>, cargoLost:Resources, captured:bool} */
function fightFor(CaptainProfile $profile, string $islandId, string $result): array
{
    $gameId = GameId::new();
    $profile->startBattle($islandId, $gameId);

    return $profile->settleBattle($gameId, $result, 'won' === $result ? 50 : 12);
}

it('nowy profil nie ma bander', function () {
    $profile = CaptainProfile::create('Jacek');

    expect($profile->ownedIslands())->toBe([])
        ->and($profile->ownsIsland('zatoka-rozbitka'))->toBeFalse();
});

it('porażka nie zdobywa wyspy, pierwsza wygrana stawia banderę z tikiem rozliczenia', function () {
    $profile = CaptainProfile::create('Jacek');

    expect(fightFor($profile, 'mielizny', 'lost')['captured'])->toBeFalse()
        ->and($profile->ownsIsland('mielizny'))->toBeFalse();

    expect(fightFor($profile, 'mielizny', 'won')['captured'])->toBeTrue()
        ->and($profile->ownedIslands())->toBe(['mielizny' => $profile->tick()]);
});

it('kolejna wygrana o posiadaną wyspę nie zdobywa jej drugi raz', function () {
    $profile = CaptainProfile::create('Jacek');
    fightFor($profile, 'mielizny', 'won');
    $capturedAt = $profile->tick();

    // zdobyta wyspa nadal przyjmuje bitwy (areną przestaje być w #78)
    expect(fightFor($profile, 'mielizny', 'won')['captured'])->toBeFalse()
        ->and($profile->ownedIslands())->toBe(['mielizny' => $capturedAt])
        ->and($profile->battleStats('mielizny'))->toBe(['wins' => 2, 'losses' => 0]);
});

it('wygrane o różne wyspy dają osobne bandery', function () {
    $profile = CaptainProfile::create('Jacek');
    fightFor($profile, 'zatoka-rozbitka', 'won');
    fightFor($profile, 'mielizny', 'won');

    expect(array_keys($profile->ownedIslands()))->toBe(['zatoka-rozbitka', 'mielizny']);
});

it('rejestr bitew zapisuje rodzaj bitwy', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('mielizny', $gameId);

    expect($profile->battles()[(string) $gameId]['kind'] ?? null)->toBe(BattleKind::Capture->value);
});

it('bandera i rodzaj bitwy przeżywają zapis i odczyt profilu', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    fightFor($profile, 'mielizny', 'won');

    $restored = $m->toDomain((string) $profile->id(), $m->toArray($profile));

    expect($restored->ownedIslands())->toBe($profile->ownedIslands())
        ->and($restored->battles())->toBe($profile->battles());
});

it('snapshot bez pola bander oznacza brak bander', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    fightFor($profile, 'mielizny', 'won');
    $state = $m->toArray($profile);
    unset($state['ownedIslands']);

    expect($m->toDomain((string) $profile->id(), $state)->ownedIslands())->toBe([]);
});

it('tylko Zatoka Rozbitka jest bezpieczną przystanią', function () {
    $harbors = array_values(array_map(
        static fn ($i) => $i->id,
        array_filter((new StaticIslandCatalog())->all(), static fn ($i) => $i->safeHarbor),
    ));

    expect($harbors)->toBe(['zatoka-rozbitka']);
});

it('bezpieczną przystań zdobywa się jak każdą wyspę', function () {
    $profile = CaptainProfile::create('Jacek');

    expect(fightFor($profile, 'zatoka-rozbitka', 'won')['captured'])->toBeTrue()
        ->and($profile->ownsIsland('zatoka-rozbitka'))->toBeTrue();
});
