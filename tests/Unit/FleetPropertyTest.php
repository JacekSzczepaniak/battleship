<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;

/** Nowy kapitan w porcie wyspy startowej (Zatoka Rozbitka) z drewnem startowym w magazynie. */
function rookieInPort(): CaptainProfile
{
    $profile = CaptainProfile::create('Jacek');
    $profile->ensureWorldState(WorldMap::generate($profile->worldSeed(), ['zatoka-rozbitka', 'mielizny', 'wyspa-sygnalowa', 'archipelag-mgiel', 'ciesnina-sztormow', 'twierdza-admiralicji']));

    return $profile;
}

it('nowy profil dostaje flotę startową: kuter + 3 tratwy i 20 drewna w magazynie wyspy startowej', function () {
    $profile = CaptainProfile::create('Jacek');

    $types = array_map(static fn ($s) => $s->type, $profile->fleet());

    expect($profile->cargo()->isZero())->toBeTrue()
        ->and($profile->unplacedResources())->toEqual(new Resources(wood: 20))
        ->and(rookieInPort()->stockpile('zatoka-rozbitka')->wood)->toBe(20)
        ->and($types)->toBe([ShipType::Kuter, ShipType::Tratwa, ShipType::Tratwa, ShipType::Tratwa])
        ->and($profile->activeFleet())->toHaveCount(4);
});

it('tratwa jest zawsze darmowa — bezpiecznik anty-softlock', function () {
    $profile = rookieInPort();

    $ship = $profile->buildShip(ShipType::Tratwa, 'zatoka-rozbitka');

    expect($profile->stockpile('zatoka-rozbitka')->wood)->toBe(20)
        ->and($ship->type)->toBe(ShipType::Tratwa)
        ->and($profile->fleet())->toHaveCount(5);
});

it('budowa kutra kosztuje drewno', function () {
    $profile = rookieInPort();

    $profile->buildShip(ShipType::Kuter, 'zatoka-rozbitka');

    expect($profile->stockpile('zatoka-rozbitka')->wood)->toBe(0);
});

it('odrzuca budowę bez surowców', function () {
    $profile = rookieInPort();
    $profile->buildShip(ShipType::Kuter, 'zatoka-rozbitka'); // drewno: 20 → 0
    $profile->buildShip(ShipType::Kuter, 'zatoka-rozbitka');
})->throws(DomainException::class, 'Not enough resources');

it('odrzuca budowę typu ponad rangę kapitana', function () {
    CaptainProfile::create('Jacek')->buildShip(ShipType::Niszczyciel, 'zatoka-rozbitka');
})->throws(DomainException::class, 'Ship type locked: requires rank marynarz');

it('wygrana bitwa: zatopione statki wymagają remontu, nie giną', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $shipIds = array_map(static fn ($s) => $s->id, $profile->activeFleet());
    $profile->startBattle('zatoka-rozbitka', $gameId, $shipIds);

    // AI zatopiło kuter (długość 2) i jedną tratwę (długość 1)
    $outcome = $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20), [2, 1]);

    expect($outcome['damaged'])->toBe(['kuter', 'tratwa'])
        ->and($outcome['lost'])->toBe([])
        ->and($profile->fleet())->toHaveCount(4)
        ->and($profile->activeFleet())->toHaveCount(2);
});

it('przegrana bitwa: zatopione statki są stracone', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $shipIds = array_map(static fn ($s) => $s->id, $profile->activeFleet());
    $profile->startBattle('zatoka-rozbitka', $gameId, $shipIds);

    // przegrana = cała wysłana flota zatopiona
    $outcome = $profile->settleBattle($gameId, 'lost', 10, new Resources(wood: 5), [2, 1, 1, 1]);

    expect($outcome['lost'])->toBe(['kuter', 'tratwa', 'tratwa', 'tratwa'])
        ->and($profile->fleet())->toHaveCount(0)
        // ale rozbitek zawsze może odbudować się darmową tratwą
        ->and($profile->buildShip(ShipType::Tratwa, 'zatoka-rozbitka')->type)->toBe(ShipType::Tratwa);
});

it('remont przywraca statek do służby za surowce', function () {
    $profile = rookieInPort();
    $profile->loadCargo('zatoka-rozbitka', new Resources(wood: 15)); // magazyn 5
    $gameId = GameId::new();
    $kuter = $profile->activeFleet()[0];
    $profile->startBattle('zatoka-rozbitka', $gameId, [$kuter->id]);
    $outcome = $profile->settleBattle($gameId, 'won', 40, new Resources(wood: 20), [2]);

    // uszkodzony kuter wozi dalej (#92): pojemność 15 zostaje, ładunek też
    expect($kuter->isDamaged())->toBeTrue()
        ->and($outcome['cargoLost']->isZero())->toBeTrue()
        ->and($profile->cargo()->wood)->toBe(15);

    $profile->repairShip($kuter->id, 'zatoka-rozbitka');

    // remont (8 drewna) płaci magazyn wyspy, w którym leży łup (5 + 20); ładownia nietknięta
    expect($kuter->isDamaged())->toBeFalse()
        ->and($profile->stockpile('zatoka-rozbitka')->wood)->toBe(17)
        ->and($profile->cargo()->wood)->toBe(15);
});

it('odrzuca remont sprawnego statku', function () {
    $profile = CaptainProfile::create('Jacek');
    $profile->repairShip($profile->fleet()[0]->id, 'zatoka-rozbitka');
})->throws(DomainException::class, 'Ship is not damaged');

it('profil sprzed ekonomii dostaje flotę startową przy odczycie snapshotu', function () {
    $profile = CaptainProfile::create('Jacek');
    $restored = CaptainProfile::fromSnapshot($profile->id(), 'Jacek', 100, []);

    expect($restored->fleet())->toHaveCount(4)
        ->and($restored->cargo()->isZero())->toBeTrue()
        ->and($restored->unplacedResources())->toEqual(new Resources(wood: 20));
});

it('bramki typów statków rosną spójnie z progresją', function () {
    foreach (ShipType::cases() as $type) {
        expect($type->repairCost()->total())->toBeLessThanOrEqual($type->buildCost()->total())
            ->and($type->length())->toBeGreaterThanOrEqual(1);
    }
    // lotniskowiec = szczyt progresji: najdroższy, najdłuższy, największa stocznia
    expect(ShipType::Lotniskowiec->requiredShipyardLevel())->toBe(3)
        ->and(ShipType::Tratwa->buildCost()->isZero())->toBeTrue();
});
