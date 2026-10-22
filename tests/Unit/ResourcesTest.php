<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

it('dodaje, odejmuje i sprawdza pokrycie kosztu', function () {
    $stock = new Resources(wood: 30, steel: 10, gold: 2);

    expect($stock->add(new Resources(steel: 5))->toArray())->toBe(['wood' => 30, 'steel' => 15, 'gold' => 2])
        ->and($stock->covers(new Resources(wood: 30, steel: 10)))->toBeTrue()
        ->and($stock->covers(new Resources(steel: 11)))->toBeFalse()
        ->and($stock->subtract(new Resources(wood: 10, steel: 10))->toArray())->toBe(['wood' => 20, 'steel' => 0, 'gold' => 2]);
});

it('odrzuca ujemne ilości i koszt ponad stan', function () {
    expect(fn () => new Resources(wood: -1))->toThrow(InvalidArgumentException::class)
        ->and(fn () => (new Resources(wood: 50))->subtract(new Resources(steel: 1)))->toThrow(DomainException::class, 'Not enough resources');
});

it('niszczyciel wymaga stali — samo drewno nie wystarcza', function () {
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', Rank::Marynarz->threshold(), [], null,
        new Resources(wood: 100),
    );

    expect(fn () => $profile->buildShip(ShipType::Niszczyciel))->toThrow(DomainException::class, 'Not enough resources');
});

it('budowa niszczyciela zdejmuje drewno i stal', function () {
    $profile = CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', Rank::Marynarz->threshold(), [], null,
        new Resources(wood: 40, steel: 25, gold: 3),
    );

    $profile->buildShip(ShipType::Niszczyciel);

    expect($profile->resources()->toArray())->toBe(['wood' => 10, 'steel' => 5, 'gold' => 3]);
});

it('rozliczenie dokłada łup z wyspy do magazynu', function () {
    $profile = CaptainProfile::create('Jacek');
    $gameId = GameId::new();
    $profile->startBattle('wyspa-sygnalowa', $gameId);

    $profile->settleBattle($gameId, 'won', 70, new Resources(wood: 20, steel: 10, gold: 5));

    expect($profile->resources()->toArray())->toBe(['wood' => 40, 'steel' => 10, 'gold' => 5]);
});

it('nagrody wysp: sumy jak dawne materiały, porażka tylko w drewnie', function () {
    $expected = [
        'zatoka-rozbitka' => [[15, 5, 0], 5],
        'mielizny' => [[18, 7, 0], 6],
        'wyspa-sygnalowa' => [[20, 10, 5], 8],
        'archipelag-mgiel' => [[20, 12, 8], 10],
        'ciesnina-sztormow' => [[20, 18, 12], 12],
        'twierdza-admiralicji' => [[20, 22, 18], 15],
    ];

    foreach ((new StaticIslandCatalog())->all() as $island) {
        [[$wood, $steel, $gold], $lossWood] = $expected[$island->id];
        expect($island->lootWin->toArray())->toBe(['wood' => $wood, 'steel' => $steel, 'gold' => $gold])
            ->and($island->lootLoss->toArray())->toBe(['wood' => $lossWood, 'steel' => 0, 'gold' => 0]);
    }
});

it('snapshot z materiałami (sprzed trzech surowców) przechodzi w drewno', function () {
    $m = new ProfileSnapshotMapper();
    $profile = CaptainProfile::create('Jacek');
    $state = $m->toArray($profile);
    unset($state['resources']);
    $state['materials'] = 37;

    expect($m->toDomain((string) $profile->id(), $state)->resources()->toArray())
        ->toBe(['wood' => 37, 'steel' => 0, 'gold' => 0]);

    $state = $m->toArray($profile);
    expect($m->toDomain((string) $profile->id(), $state)->resources())->toEqual($profile->resources());
});
