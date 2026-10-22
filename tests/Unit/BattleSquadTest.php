<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;

/** Profil z zadaną flotą (sprawną, chyba że podano uszkodzone typy). */
function profileWithFleet(array $types): CaptainProfile
{
    $fleet = array_map(static fn (ShipType $t) => OwnedShip::build($t), $types);

    return CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 1000, [], $fleet);
}

it('limit składu to 20% pól planszy wyspy', function () {
    $limits = [];
    foreach ((new StaticIslandCatalog())->all() as $island) {
        $limits[$island->id] = $island->squadCellLimit();
    }

    expect($limits)->toBe([
        'zatoka-rozbitka' => 9,     // 7×7
        'mielizny' => 12,           // 8×8
        'wyspa-sygnalowa' => 16,    // 9×9
        'archipelag-mgiel' => 20,   // 10×10
        'ciesnina-sztormow' => 20,
        'twierdza-admiralicji' => 20,
    ]);
});

it('flota w limicie płynie w całości', function () {
    $profile = CaptainProfile::create('Jacek'); // kuter + 3 tratwy = 5 pól

    expect($profile->battleSquad(null, 9))->toHaveCount(4);
});

it('domyślny skład bierze nośniki od największego, potem tratwy, do limitu', function () {
    $profile = profileWithFleet([
        ShipType::Tratwa, ShipType::Tratwa, ShipType::Tratwa, ShipType::Tratwa, ShipType::Tratwa,
        ShipType::Kuter, ShipType::Niszczyciel, ShipType::Lotniskowiec,
    ]);

    $squad = $profile->battleSquad(null, 9);

    // 4 + 3 + 2 = 9 — tratwy się nie mieszczą
    expect(array_map(static fn ($s) => $s->type, $squad))->toBe([ShipType::Lotniskowiec, ShipType::Niszczyciel, ShipType::Kuter])
        ->and(CaptainProfile::squadCells($squad))->toBe(9);
});

it('domyślny skład dopełnia limit tratwami, gdy duży nośnik się nie mieści', function () {
    $profile = profileWithFleet([ShipType::Lotniskowiec, ShipType::Lotniskowiec, ShipType::Tratwa, ShipType::Tratwa]);

    // 4 + 4 = 8 pól, dziewiąte dopełnia jedna tratwa
    $squad = $profile->battleSquad(null, 9);

    expect(CaptainProfile::squadCells($squad))->toBe(9)
        ->and($squad)->toHaveCount(3);
});

it('wybrany skład ponad limit jest odrzucany', function () {
    $profile = profileWithFleet([ShipType::Lotniskowiec, ShipType::Niszczyciel, ShipType::Kuter, ShipType::Tratwa]);
    $ids = array_map(static fn ($s) => $s->id, $profile->fleet());

    expect(fn () => $profile->battleSquad($ids, 9))->toThrow(DomainException::class, 'Squad exceeds island limit')
        ->and($profile->battleSquad(array_slice($ids, 0, 3), 9))->toHaveCount(3);
});

it('wybrany skład musi składać się z różnych, sprawnych statków floty', function () {
    $profile = CaptainProfile::create('Jacek');
    $kuter = $profile->fleet()[0];

    expect(fn () => $profile->battleSquad([$kuter->id, $kuter->id], 9))->toThrow(InvalidArgumentException::class)
        ->and(fn () => $profile->battleSquad(['nie-ma-takiego'], 9))->toThrow(DomainException::class, 'Ship not found')
        ->and(fn () => $profile->battleSquad([], 9))->toThrow(InvalidArgumentException::class);

    $kuter->markDamaged();
    expect(fn () => $profile->battleSquad([$kuter->id], 9))->toThrow(DomainException::class, 'Ship not seaworthy');
});
