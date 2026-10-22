<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;

// Uszkodzony statek zachowuje ładownię (#92): pływa i wozi, nie walczy i nie eskortuje.

/**
 * Kapitan w porcie Zatoki Rozbitka z zadaną flotą i ładunkiem.
 *
 * @param list<ShipType> $types
 *
 * @return array{0: CaptainProfile, 1: list<OwnedShip>}
 */
function haulerInPort(array $types, Resources $cargo): array
{
    $fleet = array_map(static fn (ShipType $t) => OwnedShip::build($t), $types);
    $profile = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 1000, [], $fleet, $cargo);
    $profile->ensureWorldState(WorldMap::generate($profile->worldSeed(), ['zatoka-rozbitka', 'mielizny', 'wyspa-sygnalowa', 'archipelag-mgiel', 'ciesnina-sztormow', 'twierdza-admiralicji']));

    return [$profile, $fleet];
}

it('wygrana z zatopionym kutrem nie zmienia pojemności ładowni ani ładunku', function () {
    [$profile, [$kuter]] = haulerInPort([ShipType::Kuter, ShipType::Kuter], new Resources(wood: 20, steel: 6, gold: 4));
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId, [$kuter->id]);

    $outcome = $profile->settleBattle($gameId, 'won', 40, Resources::none(), [2]);

    expect($outcome['damaged'])->toBe(['kuter'])
        ->and($outcome['cargoLost']->isZero())->toBeTrue()
        ->and($profile->cargoCapacity())->toBe(30)
        ->and($profile->cargo()->toArray())->toBe(['wood' => 20, 'steel' => 6, 'gold' => 4]);
});

it('przegrana zabiera ładownię zatopionego statku — nadmiar ładunku przepada', function () {
    [$profile, [$kuter]] = haulerInPort([ShipType::Kuter, ShipType::Kuter], new Resources(wood: 20, steel: 6, gold: 4));
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId, [$kuter->id]);

    $outcome = $profile->settleBattle($gameId, 'lost', 10, Resources::none(), [2]);

    // zostaje jeden kuter (15): najcenniejsze zostają, drewno idzie za burtę
    expect($outcome['lost'])->toBe(['kuter'])
        ->and($profile->cargoCapacity())->toBe(15)
        ->and($profile->cargo()->toArray())->toBe(['wood' => 5, 'steel' => 6, 'gold' => 4])
        ->and($outcome['cargoLost']->toArray())->toBe(['wood' => 15, 'steel' => 0, 'gold' => 0]);
});

it('uszkodzony statek nie wchodzi do składu bitewnego', function () {
    [$profile, [$kuter, $tratwa]] = haulerInPort([ShipType::Kuter, ShipType::Tratwa], Resources::none());
    $kuter->markDamaged();

    expect($profile->battleSquad(null, 9))->toBe([$tratwa])
        ->and(fn () => $profile->battleSquad([$kuter->id], 9))->toThrow(DomainException::class, 'Ship not seaworthy');
});

it('uszkodzony statek z bronią nie eskortuje konwoju', function () {
    [$profile, [, $kuter]] = haulerInPort([ShipType::Transportowiec, ShipType::Kuter], Resources::none());

    expect(CaptainProfile::isUnescortedConvoy($profile->fleet()))->toBeFalse();

    $kuter->markDamaged();

    expect(CaptainProfile::isUnescortedConvoy($profile->fleet()))->toBeTrue()
        ->and($profile->cargoCapacity())->toBe(75);
});
