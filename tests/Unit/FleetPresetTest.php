<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Game\Orientation;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// Zestawy floty (#125): skład z rozmieszczeniem zapisany w profilu, najwyżej 5,
// pozycje spełniają zasady planszy, ta sama nazwa nadpisuje zestaw.

/** Flota: niszczyciel, kuter, 2 tratwy. */
function presetProfile(): CaptainProfile
{
    $fleet = [
        new OwnedShip('n1', ShipType::Niszczyciel),
        new OwnedShip('k1', ShipType::Kuter),
        new OwnedShip('t1', ShipType::Tratwa),
        new OwnedShip('t2', ShipType::Tratwa),
    ];

    return CaptainProfile::fromSnapshot(new ProfileId('7a1c0000-0000-4000-8000-000000000125'), 'Jacek', 0, [], $fleet, Resources::none());
}

/** @return list<array{shipId: string, x: int, y: int, o: Orientation}> */
function presetPlacements(): array
{
    return [
        ['shipId' => 'n1', 'x' => 0, 'y' => 0, 'o' => Orientation::H],
        ['shipId' => 'k1', 'x' => 0, 'y' => 2, 'o' => Orientation::V],
        ['shipId' => 't1', 'x' => 4, 'y' => 4, 'o' => Orientation::H],
    ];
}

it('zapisuje zestaw z typami statków, pozycjami i rozmiarem planszy', function () {
    $profile = presetProfile();

    $preset = $profile->saveFleetPreset('  Klin  ', 7, 7, presetPlacements());

    expect($preset->toArray())->toMatchArray([
        'name' => 'Klin',
        'board' => ['w' => 7, 'h' => 7],
        'ships' => [
            ['shipId' => 'n1', 'type' => 'niszczyciel', 'x' => 0, 'y' => 0, 'o' => 'H'],
            ['shipId' => 'k1', 'type' => 'kuter', 'x' => 0, 'y' => 2, 'o' => 'V'],
            ['shipId' => 't1', 'type' => 'tratwa', 'x' => 4, 'y' => 4, 'o' => 'H'],
        ],
    ])->and($profile->fleetPresets())->toHaveCount(1)
        // porządek w kajucie nie jest akcją morską
        ->and($profile->tick())->toBe(0);
});

it('nadpisuje zestaw o tej samej nazwie bez względu na wielkość liter i zachowuje id', function () {
    $profile = presetProfile();
    $first = $profile->saveFleetPreset('Klin', 7, 7, presetPlacements());

    $second = $profile->saveFleetPreset('KLIN', 9, 9, [['shipId' => 'k1', 'x' => 1, 'y' => 1, 'o' => Orientation::H]]);

    expect($profile->fleetPresets())->toHaveCount(1)
        ->and($second->id)->toBe($first->id)
        ->and($second->name)->toBe('KLIN')
        ->and($second->boardWidth)->toBe(9);
});

it('odrzuca szósty zestaw, ale pozwala nadpisać istniejący przy pełnym limicie', function () {
    $profile = presetProfile();
    foreach (range(1, CaptainProfile::FLEET_PRESET_LIMIT) as $i) {
        $profile->saveFleetPreset("Zestaw $i", 7, 7, presetPlacements());
    }

    expect(fn () => $profile->saveFleetPreset('Zestaw 6', 7, 7, presetPlacements()))
        ->toThrow(DomainException::class, 'Fleet preset limit exceeded');

    $profile->saveFleetPreset('Zestaw 3', 8, 8, presetPlacements());
    expect($profile->fleetPresets())->toHaveCount(5);
});

it('odrzuca statki stykające się, wychodzące poza planszę, powtórzone i spoza floty', function (array $placements, string $message) {
    expect(fn () => presetProfile()->saveFleetPreset('Zły', 7, 7, $placements))->toThrow(Exception::class, $message);
})->with([
    'styk po skosie' => [[
        ['shipId' => 'k1', 'x' => 0, 'y' => 0, 'o' => Orientation::H],
        ['shipId' => 't1', 'x' => 2, 'y' => 1, 'o' => Orientation::H],
    ], 'Ships overlap or touch'],
    'poza planszą' => [[['shipId' => 'n1', 'x' => 5, 'y' => 0, 'o' => Orientation::H]], 'Ship out of board bounds'],
    'powtórzony statek' => [[
        ['shipId' => 't1', 'x' => 0, 'y' => 0, 'o' => Orientation::H],
        ['shipId' => 't1', 'x' => 4, 'y' => 4, 'o' => Orientation::H],
    ], 'Squad must list distinct ships'],
    'statek spoza floty' => [[['shipId' => 'obcy', 'x' => 0, 'y' => 0, 'o' => Orientation::H]], 'Ship not found'],
    'pusty zestaw' => [[], 'Fleet preset must place at least one ship'],
]);

it('odrzuca pustą i za długą nazwę', function (string $name) {
    expect(fn () => presetProfile()->saveFleetPreset($name, 7, 7, presetPlacements()))
        ->toThrow(InvalidArgumentException::class, 'Fleet preset name must be 1-30 characters');
})->with(['   ', str_repeat('x', 31)]);

it('zmienia nazwę, ale nie na nazwę innego zestawu', function () {
    $profile = presetProfile();
    $klin = $profile->saveFleetPreset('Klin', 7, 7, presetPlacements());
    $profile->saveFleetPreset('Łańcuch', 7, 7, presetPlacements());

    expect($profile->renameFleetPreset($klin->id, 'Grot')->name)->toBe('Grot')
        // ta sama nazwa w innej wielkości liter to zmiana tego samego zestawu
        ->and($profile->renameFleetPreset($klin->id, 'GROT')->name)->toBe('GROT')
        ->and(fn () => $profile->renameFleetPreset($klin->id, 'łańcuch'))->toThrow(DomainException::class, 'Fleet preset name taken')
        ->and(fn () => $profile->renameFleetPreset('brak', 'X'))->toThrow(DomainException::class, 'Fleet preset not found');
});

it('usuwa zestaw', function () {
    $profile = presetProfile();
    $klin = $profile->saveFleetPreset('Klin', 7, 7, presetPlacements());

    $profile->deleteFleetPreset($klin->id);

    expect($profile->fleetPresets())->toBe([])
        ->and(fn () => $profile->deleteFleetPreset($klin->id))->toThrow(DomainException::class, 'Fleet preset not found');
});

it('round-tripuje zestawy w snapshocie, a stary profil bez klucza nie ma zestawów', function () {
    $m = new ProfileSnapshotMapper();
    $profile = presetProfile();
    $klin = $profile->saveFleetPreset('Klin', 7, 7, presetPlacements());

    $state = $m->toArray($profile);
    $restored = $m->toDomain((string) $profile->id(), $state);
    expect(array_map(fn ($p) => $p->toArray(), $restored->fleetPresets()))->toBe([$klin->toArray()]);

    unset($state['fleetPresets']);
    expect($m->toDomain((string) $profile->id(), $state)->fleetPresets())->toBe([]);
});

it('zestaw przeżywa utratę statku — wpis zostaje, wczytanie go pominie', function () {
    $m = new ProfileSnapshotMapper();
    $profile = presetProfile();
    $profile->saveFleetPreset('Klin', 7, 7, presetPlacements());
    $state = $m->toArray($profile);
    $state['fleet'] = array_values(array_filter($state['fleet'], static fn (array $s) => 'n1' !== $s['id']));

    $restored = $m->toDomain((string) $profile->id(), $state);

    expect(array_column($restored->fleetPresets()[0]->toArray()['ships'], 'shipId'))->toBe(['n1', 'k1', 't1'])
        ->and(array_map(fn ($s) => $s->id, $restored->fleet()))->toBe(['k1', 't1', 't2']);
});

it('złomowany statek (#124) zostaje w zestawie, ale nie da się go zapisać ponownie', function () {
    $profile = presetProfile();
    $profile->saveFleetPreset('Klin', 7, 7, presetPlacements());

    $profile->scrapShip('t1', 'zatoka-rozbitka');

    expect(array_column($profile->fleetPresets()[0]->toArray()['ships'], 'shipId'))->toBe(['n1', 'k1', 't1'])
        ->and(fn () => $profile->saveFleetPreset('Klin', 7, 7, presetPlacements()))->toThrow(DomainException::class, 'Ship not found');
});

it('pomija w snapshocie zestaw z nieczytelnym wpisem statku', function () {
    $m = new ProfileSnapshotMapper();
    $profile = presetProfile();
    $profile->saveFleetPreset('Klin', 7, 7, presetPlacements());
    $profile->saveFleetPreset('Grot', 7, 7, presetPlacements());
    $state = $m->toArray($profile);
    $state['fleetPresets'][0]['ships'][1]['type'] = 'okręt-widmo';

    expect(array_map(fn ($p) => $p->name, $m->toDomain((string) $profile->id(), $state)->fleetPresets()))->toBe(['Grot']);
});
