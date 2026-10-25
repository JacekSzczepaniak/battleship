<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\GameId;

function noAwards(): array
{
    return ['xp' => 0, 'loot' => Resources::none(), 'lost' => [], 'damaged' => [], 'cargoLost' => Resources::none(), 'captured' => false, 'besieged' => false, 'defense' => null, 'gateOpened' => false, 'prize' => null, 'lootBonus' => Resources::none()];
}

it('startuje jako rozbitek z zerowym XP', function () {
    $profile = CaptainProfile::create('Jacek');

    expect($profile->xp())->toBe(0)
        ->and($profile->rank())->toBe(Rank::Rozbitek);
});

it('odrzuca pustą i za długą nazwę', function (string $name) {
    CaptainProfile::create($name);
})->with(['', '   ', str_repeat('x', 41)])->throws(InvalidArgumentException::class);

it('rozlicza wygraną bitwę i nalicza XP oraz surowce', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);

    $outcome = $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20));

    expect($outcome['xp'])->toBe(40)
        ->and($outcome['loot'])->toEqual(new Resources(wood: 20))
        ->and($profile->xp())->toBe(40)
        ->and($profile->battleStats('zatoka-rozbitka'))->toBe(['wins' => 1, 'losses' => 0]);
});

it('nalicza XP i surowce także za przegraną — doświadczenia się nie traci', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('mielizny', $gameId);
    $cargo = $profile->cargo();

    $outcome = $profile->settleBattle($gameId, 'lost', 12, new Resources(wood: 6));

    expect($outcome['xp'])->toBe(12)
        ->and($profile->xp())->toBe(12)
        ->and($profile->cargo())->toEqual($cargo) // ładownia bez zmian
        ->and($profile->stockpile('mielizny')->wood)->toBe(6) // łup leży w magazynie wyspy bitwy
        ->and($profile->battleStats('mielizny'))->toBe(['wins' => 0, 'losses' => 1]);
});

it('rozliczenie jest idempotentne — druga próba nie daje nagród', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20));

    expect($profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20)))->toEqual(noAwards())
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

    // zdobyta wyspa nie jest już areną (#78) — dwie różne wyspy
    foreach (['zatoka-rozbitka', 'mielizny'] as $islandId) {
        $gameId = GameId::new();
        $profile->startBattle($islandId, $gameId);
        $profile->settleBattle($gameId, 'won', 40);
    }

    expect($profile->xp())->toBe(80)
        ->and($profile->rank())->toBe(Rank::Marynarz);
});

it('round-tripuje przez snapshot', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20));

    $restored = CaptainProfile::fromSnapshot(
        $profile->id(),
        $profile->name(),
        $profile->xp(),
        $profile->battles(),
        $profile->fleet(),
        $profile->cargo(),
        stockpiles: $profile->stockpiles(),
        unplaced: $profile->unplacedResources(),
    );

    expect($restored->xp())->toBe(40)
        ->and($restored->unplacedResources())->toEqual(new Resources(wood: 20)) // drewno startowe czeka na magazyn wyspy
        ->and($restored->stockpile('zatoka-rozbitka')->wood)->toBe(20) // łup w magazynie wyspy
        ->and($restored->battleStats('zatoka-rozbitka'))->toBe(['wins' => 1, 'losses' => 0])
        ->and($restored->settleBattle($gameId, 'won', 40, new Resources(wood: 20)))->toEqual(noAwards());
});

it('liczy jednostki wroga zatopione w rozliczonych bitwach', function () {
    $profile = CaptainProfile::create('Jacek');
    $won = GameId::new();
    $lost = GameId::new();
    $open = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $won);
    $profile->startBattle('zatoka-rozbitka', $lost);
    $profile->startBattle('zatoka-rozbitka', $open);

    $profile->settleBattle($won, 'won', 40, new Resources(wood: 20), [], 4);
    $profile->settleBattle($lost, 'lost', 10, new Resources(wood: 5), [], 2);

    // nierozliczona bitwa nic nie dokłada; porażka też liczy zatopienia
    expect($profile->enemyShipsSunk())->toBe(6);
});

it('nie podwaja zatopień przy ponownym rozliczeniu', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20), [], 4);
    $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20), [], 4);

    expect($profile->enemyShipsSunk())->toBe(4);
});

it('odrzuca ujemną liczbę zatopień', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20), [], -1);
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

    $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20));
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
