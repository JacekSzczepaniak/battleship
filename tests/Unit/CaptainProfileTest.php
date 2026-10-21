<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Rank;
use App\Domain\Shared\GameId;

const NO_AWARDS = ['xp' => 0, 'materials' => 0, 'lost' => [], 'damaged' => []];

it('startuje jako rozbitek z zerowym XP', function () {
    $profile = CaptainProfile::create('Jacek');

    expect($profile->xp())->toBe(0)
        ->and($profile->rank())->toBe(Rank::Rozbitek);
});

it('odrzuca pustą i za długą nazwę', function (string $name) {
    CaptainProfile::create($name);
})->with(['', '   ', str_repeat('x', 41)])->throws(InvalidArgumentException::class);

it('rozlicza wygraną bitwę i nalicza XP oraz materiały', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);

    $outcome = $profile->settleBattle($gameId, 'won', 40, 20);

    expect($outcome['xp'])->toBe(40)
        ->and($outcome['materials'])->toBe(20)
        ->and($profile->xp())->toBe(40)
        ->and($profile->battleStats('zatoka-rozbitka'))->toBe(['wins' => 1, 'losses' => 0]);
});

it('nalicza XP i materiały także za przegraną — doświadczenia się nie traci', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('mielizny', $gameId);

    $outcome = $profile->settleBattle($gameId, 'lost', 12, 6);

    expect($outcome['xp'])->toBe(12)
        ->and($profile->xp())->toBe(12)
        ->and($profile->materials())->toBe(26) // 20 startowych + 6
        ->and($profile->battleStats('mielizny'))->toBe(['wins' => 0, 'losses' => 1]);
});

it('rozliczenie jest idempotentne — druga próba nie daje nagród', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, 20);

    expect($profile->settleBattle($gameId, 'won', 40, 20))->toBe(NO_AWARDS)
        ->and($profile->xp())->toBe(40);
});

it('odrzuca rozliczenie niezarejestrowanej bitwy', function () {
    CaptainProfile::create('Jacek')->settleBattle(GameId::new(), 'won', 40);
})->throws(DomainException::class, 'Battle not registered for this profile');

it('odrzuca podwójną rejestrację tej samej gry', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->startBattle('mielizny', $gameId);
})->throws(DomainException::class, 'Battle already registered');

it('awansuje wraz z progami XP', function () {
    $profile = CaptainProfile::create('Jacek');

    foreach (range(1, 2) as $i) {
        $gameId = GameId::new();
        $profile->startBattle('zatoka-rozbitka', $gameId);
        $profile->settleBattle($gameId, 'won', 40);
    }

    expect($profile->xp())->toBe(80)
        ->and($profile->rank())->toBe(Rank::Marynarz);
});

it('round-tripuje przez snapshot', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, 20);

    $restored = CaptainProfile::fromSnapshot(
        $profile->id(),
        $profile->name(),
        $profile->xp(),
        $profile->battles(),
        $profile->fleet(),
        $profile->materials(),
    );

    expect($restored->xp())->toBe(40)
        ->and($restored->materials())->toBe(40) // 20 startowych + 20 z bitwy
        ->and($restored->battleStats('zatoka-rozbitka'))->toBe(['wins' => 1, 'losses' => 0])
        ->and($restored->settleBattle($gameId, 'won', 40, 20))->toBe(NO_AWARDS);
});

it('liczy jednostki wroga zatopione w rozliczonych bitwach', function () {
    $profile = CaptainProfile::create('Jacek');
    $won = GameId::new();
    $lost = GameId::new();
    $open = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $won);
    $profile->startBattle('zatoka-rozbitka', $lost);
    $profile->startBattle('zatoka-rozbitka', $open);

    $profile->settleBattle($won, 'won', 40, 20, [], 4);
    $profile->settleBattle($lost, 'lost', 10, 5, [], 2);

    // nierozliczona bitwa nic nie dokłada; porażka też liczy zatopienia
    expect($profile->enemyShipsSunk())->toBe(6);
});

it('nie podwaja zatopień przy ponownym rozliczeniu', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, 20, [], 4);
    $profile->settleBattle($gameId, 'won', 40, 20, [], 4);

    expect($profile->enemyShipsSunk())->toBe(4);
});

it('odrzuca ujemną liczbę zatopień', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, 20, [], -1);
})->throws(InvalidArgumentException::class);

it('rekord bitwy sprzed licznika zatopień liczy się jako zero', function () {
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(),
        'Jacek',
        40,
        [(string) GameId::new() => ['island' => 'zatoka-rozbitka', 'settled' => true, 'result' => 'won']],
    );

    expect($profile->enemyShipsSunk())->toBe(0)
        ->and($profile->battleStats('zatoka-rozbitka'))->toBe(['wins' => 1, 'losses' => 0]);
});

it('rejs i start bitwy kosztują tik, rozliczenie nie', function () {
    $profile = CaptainProfile::create('Jacek');
    expect($profile->tick())->toBe(0);

    $world = App\Domain\Expedition\WorldMap::generate($profile->worldSeed(), ['zatoka-rozbitka', 'mielizny']);
    $profile->ensureWorldState($world);
    $pos = $profile->position();
    $profile->sail($pos['x'] + ($pos['x'] > 0 ? -1 : 1), $pos['y'], $world);
    expect($profile->tick())->toBe(1)
        ->and($profile->moveCount())->toBe(1);

    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    expect($profile->tick())->toBe(2)
        ->and($profile->moveCount())->toBe(1); // moveCount liczy tylko rejsy

    $profile->settleBattle($gameId, 'won', 40, 20);
    expect($profile->tick())->toBe(2);
});

it('profil sprzed tiku dostaje tik z historii: rejsy + zarejestrowane bitwy', function () {
    $id = CaptainProfile::create('Jacek')->id();
    $battles = [
        (string) GameId::new() => ['island' => 'zatoka-rozbitka', 'settled' => true, 'result' => 'won'],
        (string) GameId::new() => ['island' => 'zatoka-rozbitka', 'settled' => false, 'result' => null],
    ];

    $legacy = CaptainProfile::fromSnapshot($id, 'Jacek', 40, $battles, null, null, null, null, null, 5);
    $current = CaptainProfile::fromSnapshot($id, 'Jacek', 40, $battles, null, null, null, null, null, 5, 11);

    expect($legacy->tick())->toBe(7)
        ->and($current->tick())->toBe(11);
});
