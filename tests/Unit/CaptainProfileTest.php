<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Rank;
use App\Domain\Shared\GameId;

it('startuje jako rozbitek z zerowym XP', function () {
    $profile = CaptainProfile::create('Jacek');

    expect($profile->xp())->toBe(0)
        ->and($profile->rank())->toBe(Rank::Rozbitek);
});

it('odrzuca pustą i za długą nazwę', function (string $name) {
    CaptainProfile::create($name);
})->with(['', '   ', str_repeat('x', 41)])->throws(InvalidArgumentException::class);

it('rozlicza wygraną bitwę i nalicza XP', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);

    expect($profile->settleBattle($gameId, 'won', 40))->toBe(40)
        ->and($profile->xp())->toBe(40)
        ->and($profile->battleStats('zatoka-rozbitka'))->toBe(['wins' => 1, 'losses' => 0]);
});

it('nalicza XP także za przegraną — doświadczenia się nie traci', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('mielizny', $gameId);

    expect($profile->settleBattle($gameId, 'lost', 12))->toBe(12)
        ->and($profile->xp())->toBe(12)
        ->and($profile->battleStats('mielizny'))->toBe(['wins' => 0, 'losses' => 1]);
});

it('rozliczenie jest idempotentne — druga próba nie daje XP', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40);

    expect($profile->settleBattle($gameId, 'won', 40))->toBe(0)
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
    $profile->settleBattle($gameId, 'won', 40);

    $restored = CaptainProfile::fromSnapshot($profile->id(), $profile->name(), $profile->xp(), $profile->battles());

    expect($restored->xp())->toBe(40)
        ->and($restored->battleStats('zatoka-rozbitka'))->toBe(['wins' => 1, 'losses' => 0])
        ->and($restored->settleBattle($gameId, 'won', 40))->toBe(0);
});
