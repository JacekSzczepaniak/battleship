<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

it('round-tripuje liczbę zatopionych jednostek wroga', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, 20, [], 3);

    $restored = $m->toDomain((string) $profile->id(), $m->toArray($profile));

    expect($restored->enemyShipsSunk())->toBe(3);
});

it('snapshot bez klucza enemySunk (profil sprzed licznika) daje zero zatopień', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    $state = $m->toArray($profile);
    $state['battles'] = [
        (string) GameId::new() => ['island' => 'zatoka-rozbitka', 'settled' => true, 'result' => 'won'],
    ];

    $restored = $m->toDomain((string) $profile->id(), $state);

    expect($restored->enemyShipsSunk())->toBe(0)
        ->and($restored->battleStats('zatoka-rozbitka'))->toBe(['wins' => 1, 'losses' => 0]);
});
