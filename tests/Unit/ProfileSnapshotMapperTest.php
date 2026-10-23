<?php

declare(strict_types=1);

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaState;
use App\Domain\Expedition\StaticIslandCatalog;
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

    // snapshot sprzed odwiedzin jest też sprzed akwenów: płaska pozycja i odkrycia, bez `visited`
    $state['position'] = $profile->position();
    $state['discovered'] = $profile->discoveredSectors();
    unset($state['sea'], $state['seas']);
    $legacy = $m->toDomain((string) $profile->id(), $state);
    $pos = $profile->position();
    expect($legacy->visitedSectors())->toBe(["{$pos['x']}:{$pos['y']}"]);
});

it('round-tripuje rozbudowę stoczni; profil sprzed rozbudowy ma stocznię z katalogu', function () {
    $m = new ProfileSnapshotMapper();
    $zatoka = (new StaticIslandCatalog())->byId('zatoka-rozbitka');
    $profile = CaptainProfile::create('Jacek');
    $state = $m->toArray($profile);
    $state['ownedIslands'] = ['zatoka-rozbitka' => 0];

    unset($state['buildings']);
    expect($m->toDomain((string) $profile->id(), $state)->shipyardLevel($zatoka))->toBe(1);

    $state['buildings'] = ['zatoka-rozbitka' => ['rozbudowa-stoczni']];
    $restored = $m->toDomain((string) $profile->id(), $state);
    expect($restored->buildingsOn('zatoka-rozbitka'))->toBe([BuildingType::RozbudowaStoczni])
        ->and($restored->shipyardLevel($zatoka))->toBe(2)
        ->and($m->toArray($restored)['buildings'])->toBe(['zatoka-rozbitka' => ['rozbudowa-stoczni']]);
});

it('stary profil sprzed akwenów wczytuje się do Morza Rozbitków z tą samą pozycją, odkryciami i odwiedzinami', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    $state = $m->toArray($profile);
    unset($state['sea'], $state['seas']);
    $state['position'] = ['x' => 4, 'y' => 7];
    $state['discovered'] = ['3:6', '4:6', '4:7', '5:8'];
    $state['visited'] = ['4:6', '4:7'];

    $legacy = $m->toDomain((string) $profile->id(), $state);

    expect($legacy->currentSea())->toBe(Sea::START)
        ->and($legacy->position())->toBe(['x' => 4, 'y' => 7])
        ->and($legacy->discoveredSectors())->toBe(['3:6', '4:6', '4:7', '5:8'])
        ->and($legacy->visitedSectors())->toBe(['4:6', '4:7'])
        ->and(array_keys($legacy->seaStates()))->toBe([Sea::START]);

    // zapis idzie już w kształcie per akwen, bez płaskich pól
    $saved = $m->toArray($legacy);
    expect($saved)->not->toHaveKeys(['position', 'discovered', 'visited'])
        ->and($saved['sea'])->toBe(Sea::START)
        ->and($saved['seas'][Sea::START])->toBe([
            'position' => ['x' => 4, 'y' => 7],
            'discovered' => ['3:6', '4:6', '4:7', '5:8'],
            'visited' => ['4:6', '4:7'],
        ]);
});

it('round-tripuje akwen floty i stan każdego akwenu', function () {
    $m = new ProfileSnapshotMapper();
    $id = CaptainProfile::create('Jacek')->id();
    $profile = CaptainProfile::fromSnapshot(
        $id, 'Jacek', 0, [], null, Resources::none(), 7, ['x' => 1, 'y' => 1], ['1:1'],
        visited: ['1:1'],
        sea: 'ocean-sztormow',
        seaStates: [Sea::START => new SeaState(['x' => 9, 'y' => 2], ['9:2', '9:3'], ['9:2'])],
    );

    $restored = $m->toDomain((string) $id, $m->toArray($profile));

    expect($restored->currentSea())->toBe('ocean-sztormow')
        ->and($restored->position())->toBe(['x' => 1, 'y' => 1])
        ->and($restored->discoveredSectors())->toBe(['1:1'])
        ->and($restored->visitedSectors())->toBe(['1:1'])
        ->and($restored->seaStates()[Sea::START]->position())->toBe(['x' => 9, 'y' => 2])
        ->and($restored->seaStates()[Sea::START]->discoveredSectors())->toBe(['9:2', '9:3'])
        ->and($restored->seaStates()[Sea::START]->visitedSectors())->toBe(['9:2']);
});
