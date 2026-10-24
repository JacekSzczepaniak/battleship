<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\FleetRepair;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\RepairSkip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\Technology;

/**
 * Kapitan z uszkodzoną flotą i magazynem Zatoki Rozbitka (#151).
 *
 * @param list<ShipType>   $damaged
 * @param list<Technology> $researched
 *
 * @return array{0: CaptainProfile, 1: list<OwnedShip>}
 */
function damagedFleetCaptain(array $damaged, Resources $stock, ?Resources $cargo = null, array $researched = []): array
{
    $fleet = array_map(static fn (ShipType $t) => OwnedShip::build($t), $damaged);
    foreach ($fleet as $ship) {
        $ship->markDamaged();
    }
    $sound = OwnedShip::build(ShipType::Kuter);
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 600, [], [...$fleet, $sound], $cargo ?? Resources::none(), 1, null,
        tick: 7,
        stockpiles: ['zatoka-rozbitka' => $stock],
        researched: $researched,
    );

    return [$profile, $fleet];
}

/** @return array{repaired: list<string>, skipped: list<array{0: string, 1: RepairSkip}>} */
function repairOutline(FleetRepair $repair): array
{
    return [
        'repaired' => array_map(static fn (OwnedShip $s) => $s->id, $repair->repaired),
        'skipped' => array_map(static fn (array $skip) => [$skip['ship']->id, $skip['reason']], $repair->skipped),
    ];
}

it('remontuje od najtańszego i pomija statek, na który zabrakło surowców, ale remontuje dalej', function () {
    // kuter 8 drewna, niszczyciel 10+10 i transportowiec 15+5 (ten sam koszt łączny — kolejność floty)
    [$profile, [$niszczyciel, $kuter, $transportowiec]] = damagedFleetCaptain(
        [ShipType::Niszczyciel, ShipType::Kuter, ShipType::Transportowiec],
        new Resources(wood: 25, steel: 5),
    );

    expect(array_map(static fn (OwnedShip $s) => $s->id, $profile->repairQueue()))->toBe([$kuter->id, $niszczyciel->id, $transportowiec->id]);

    $repair = $profile->repairFleet('zatoka-rozbitka', 2);

    expect(repairOutline($repair))->toBe([
        'repaired' => [$kuter->id, $transportowiec->id],
        'skipped' => [[$niszczyciel->id, RepairSkip::NotEnoughResources]],
    ])
        ->and($repair->paid->toArray())->toBe(['wood' => 23, 'steel' => 5, 'gold' => 0])
        ->and($profile->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 2, 'steel' => 0, 'gold' => 0])
        ->and($niszczyciel->isDamaged())->toBeTrue()
        ->and($kuter->isDamaged() || $transportowiec->isDamaged())->toBeFalse()
        ->and($profile->tick())->toBe(7);
});

it('pomija statki za ciężkie dla stoczni, a brakującą resztę bierze z ładowni', function () {
    [$profile, [$lotniskowiec, $kuter, $tratwa]] = damagedFleetCaptain(
        [ShipType::Lotniskowiec, ShipType::Kuter, ShipType::Tratwa],
        new Resources(wood: 5),
        cargo: new Resources(wood: 3, gold: 2),
    );

    $repair = $profile->repairFleet('zatoka-rozbitka', 1);

    expect(repairOutline($repair))->toBe([
        'repaired' => [$tratwa->id, $kuter->id],
        'skipped' => [[$lotniskowiec->id, RepairSkip::ShipyardTooLow]],
    ])
        ->and($repair->paid->toArray())->toBe(['wood' => 8, 'steel' => 0, 'gold' => 0])
        ->and($profile->stockpile('zatoka-rozbitka')->isZero())->toBeTrue()
        ->and($profile->cargo()->toArray())->toBe(['wood' => 0, 'steel' => 0, 'gold' => 2]);
});

it('z Dokiem remontowym liczy kolejność i koszt po połowie ceny', function () {
    // z Dokiem: lotniskowiec 10+13, niszczyciel 5+5, kuter 4
    [$profile, [$lotniskowiec, $niszczyciel, $kuter]] = damagedFleetCaptain(
        [ShipType::Lotniskowiec, ShipType::Niszczyciel, ShipType::Kuter],
        new Resources(wood: 19, steel: 18),
        researched: [Technology::DokRemontowy],
    );

    $repair = $profile->repairFleet('zatoka-rozbitka', 3);

    expect(repairOutline($repair))->toBe(['repaired' => [$kuter->id, $niszczyciel->id, $lotniskowiec->id], 'skipped' => []])
        ->and($repair->paid->toArray())->toBe(['wood' => 19, 'steel' => 18, 'gold' => 0])
        ->and($profile->activeFleet())->toHaveCount(4);
});

it('bez uszkodzonych statków nic nie remontuje i nic nie pobiera', function () {
    [$profile] = damagedFleetCaptain([], new Resources(wood: 10));

    $repair = $profile->repairFleet('zatoka-rozbitka', 3);

    expect(repairOutline($repair))->toBe(['repaired' => [], 'skipped' => []])
        ->and($repair->paid->isZero())->toBeTrue()
        ->and($profile->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 10, 'steel' => 0, 'gold' => 0]);
});
