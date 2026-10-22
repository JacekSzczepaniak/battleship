<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

it('round-tripuje liczbę zatopionych jednostek wroga', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20), [], 3);

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

it('round-tripuje tik, a snapshot bez klucza tick liczy go z historii', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);

    $state = $m->toArray($profile);
    expect($m->toDomain((string) $profile->id(), $state)->tick())->toBe(1);

    unset($state['tick']);
    $state['moveCount'] = 3;
    // 3 rejsy + 1 zarejestrowana bitwa
    expect($m->toDomain((string) $profile->id(), $state)->tick())->toBe(4);
});

it('round-tripuje odwiedzone sektory; stary snapshot zna tylko obecną pozycję', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    $world = WorldMap::generate($profile->worldSeed(), ['zatoka-rozbitka', 'mielizny']);
    $profile->ensureWorldState($world);
    $start = $profile->position();
    $profile->sail($start['x'] + ($start['x'] > 0 ? -1 : 1), $start['y'], $world);

    $state = $m->toArray($profile);
    $restored = $m->toDomain((string) $profile->id(), $state);
    $sorted = static function (array $s): array {
        sort($s);

        return $s;
    };
    expect($sorted($restored->visitedSectors()))->toBe($sorted($profile->visitedSectors()))
        ->and($restored->visitedSectors())->toHaveCount(2);

    unset($state['visited']);
    $legacy = $m->toDomain((string) $profile->id(), $state);
    $pos = $profile->position();
    expect($legacy->visitedSectors())->toBe(["{$pos['x']}:{$pos['y']}"]);
});
