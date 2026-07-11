<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ShipType;
use App\Domain\Shared\GameId;

it('nowy profil dostaje flotę startową: kuter + 3 tratwy i 20 materiałów', function () {
    $profile = CaptainProfile::create('Jacek');

    $types = array_map(static fn ($s) => $s->type, $profile->fleet());

    expect($profile->materials())->toBe(20)
        ->and($types)->toBe([ShipType::Kuter, ShipType::Tratwa, ShipType::Tratwa, ShipType::Tratwa])
        ->and($profile->activeFleet())->toHaveCount(4);
});

it('tratwa jest zawsze darmowa — bezpiecznik anty-softlock', function () {
    $profile = CaptainProfile::create('Jacek');

    $ship = $profile->buildShip(ShipType::Tratwa);

    expect($profile->materials())->toBe(20)
        ->and($ship->type)->toBe(ShipType::Tratwa)
        ->and($profile->fleet())->toHaveCount(5);
});

it('budowa kutra kosztuje materiały', function () {
    $profile = CaptainProfile::create('Jacek');

    $profile->buildShip(ShipType::Kuter);

    expect($profile->materials())->toBe(0);
});

it('odrzuca budowę bez materiałów', function () {
    $profile = CaptainProfile::create('Jacek');
    $profile->buildShip(ShipType::Kuter); // materiały: 20 → 0
    $profile->buildShip(ShipType::Kuter);
})->throws(DomainException::class, 'Not enough materials');

it('odrzuca budowę typu ponad rangę kapitana', function () {
    CaptainProfile::create('Jacek')->buildShip(ShipType::Niszczyciel);
})->throws(DomainException::class, 'Ship type locked: requires rank marynarz');

it('wygrana bitwa: zatopione statki wymagają remontu, nie giną', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $shipIds = array_map(static fn ($s) => $s->id, $profile->activeFleet());
    $profile->startBattle('zatoka-rozbitka', $gameId, $shipIds);

    // AI zatopiło kuter (długość 2) i jedną tratwę (długość 1)
    $outcome = $profile->settleBattle($gameId, 'won', 40, 20, [2, 1]);

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
    $outcome = $profile->settleBattle($gameId, 'lost', 10, 5, [2, 1, 1, 1]);

    expect($outcome['lost'])->toBe(['kuter', 'tratwa', 'tratwa', 'tratwa'])
        ->and($profile->fleet())->toHaveCount(0)
        // ale rozbitek zawsze może odbudować się darmową tratwą
        ->and($profile->buildShip(ShipType::Tratwa)->type)->toBe(ShipType::Tratwa);
});

it('remont przywraca statek do służby za materiały', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $kuter = $profile->activeFleet()[0];
    $profile->startBattle('zatoka-rozbitka', $gameId, [$kuter->id]);
    $profile->settleBattle($gameId, 'won', 40, 20, [2]);

    expect($kuter->isDamaged())->toBeTrue();

    $profile->repairShip($kuter->id);

    expect($kuter->isDamaged())->toBeFalse()
        ->and($profile->materials())->toBe(32); // 20 + 20 - 8 (remont kutra)
});

it('odrzuca remont sprawnego statku', function () {
    $profile = CaptainProfile::create('Jacek');
    $profile->repairShip($profile->fleet()[0]->id);
})->throws(DomainException::class, 'Ship is not damaged');

it('profil sprzed ekonomii dostaje flotę startową przy odczycie snapshotu', function () {
    $profile = CaptainProfile::create('Jacek');
    $restored = CaptainProfile::fromSnapshot($profile->id(), 'Jacek', 100, []);

    expect($restored->fleet())->toHaveCount(4)
        ->and($restored->materials())->toBe(20);
});

it('bramki typów statków rosną spójnie z progresją', function () {
    foreach (ShipType::cases() as $type) {
        expect($type->repairCost())->toBeLessThanOrEqual($type->buildCost())
            ->and($type->length())->toBeGreaterThanOrEqual(1);
    }
    // lotniskowiec = szczyt progresji: najdroższy, najdłuższy, największa stocznia
    expect(ShipType::Lotniskowiec->requiredShipyardLevel())->toBe(3)
        ->and(ShipType::Tratwa->buildCost())->toBe(0);
});
