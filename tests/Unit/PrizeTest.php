<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\PrizeDecision;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// Statek zdobyty abordażem (#118): czeka w profilu na decyzję — dołącz
// (uszkodzony, bez wymogu rangi) albo rozbierz (połowa kosztu budowy do ładowni).

/** Profil po bitwie z abordażem; flota: 2 kutry (ładownia 2 × 15). */
function prizeProfile(ShipType $type = ShipType::Lotniskowiec, ?ShipVariant $variant = null, string $result = 'won', ?Resources $cargo = null): CaptainProfile
{
    $fleet = [new OwnedShip('k1', ShipType::Kuter), new OwnedShip('k2', ShipType::Kuter)];
    $profile = CaptainProfile::fromSnapshot(new ProfileId('7a1c0000-0000-4000-8000-000000000118'), 'Jacek', 0, [], $fleet, $cargo ?? Resources::none());
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId);
    $profile->settleBattle($gameId, $result, 10, boardedType: $type, boardedVariant: $variant);

    return $profile;
}

it('typ zdobyczy wynika z długości statku', function () {
    expect(ShipType::ofLength(4))->toBe(ShipType::Lotniskowiec)
        ->and(ShipType::ofLength(1))->toBe(ShipType::Tratwa)
        ->and(ShipType::ofLength(9))->toBeNull();
});

it('wygrana z abordażem zostawia zdobycz i wpis w dzienniku; przegrana nie', function () {
    $profile = prizeProfile(ShipType::Niszczyciel, ShipVariant::Zwiadowczy);

    [$prize] = $profile->prizes();
    expect($prize->type)->toBe(ShipType::Niszczyciel)
        ->and($prize->variant)->toBe(ShipVariant::Zwiadowczy)
        ->and(array_slice($profile->log(), -1)[0]->event)->toBe(LogEvent::ShipBoarded)
        ->and(array_slice($profile->log(), -1)[0]->data)->toBe(['ship' => 'niszczyciel', 'variant' => 'zwiadowczy', 'island' => 'zatoka-rozbitka'])
        ->and(prizeProfile(result: 'lost')->prizes())->toBe([]);
});

it('dołącz: statek uszkodzony trafia do floty bez wymogu rangi', function () {
    $profile = prizeProfile(ShipType::Lotniskowiec);
    expect($profile->rank())->toBe(Rank::Rozbitek);

    $out = $profile->decidePrize($profile->prizes()[0]->id, PrizeDecision::Join);

    expect($out['ship']?->type)->toBe(ShipType::Lotniskowiec)
        ->and($out['ship']?->isDamaged())->toBeTrue()
        ->and($profile->fleet())->toHaveCount(3)
        ->and($profile->prizes())->toBe([])
        ->and(array_slice($profile->log(), -1)[0]->data)->toBe(['ship' => 'lotniskowiec', 'decision' => 'join']);
});

it('rozbierz: połowa kosztu budowy do ładowni, nadmiar przepada', function () {
    // lotniskowiec kosztuje 60 drewna i 60 stali; ładownia 2 kutrów = 30, zajęte 10
    $profile = prizeProfile(ShipType::Lotniskowiec, cargo: new Resources(gold: 10));
    expect($profile->prizes()[0]->scrapValue())->toEqual(new Resources(wood: 30, steel: 30));

    $out = $profile->decidePrize($profile->prizes()[0]->id, PrizeDecision::Scrap);

    expect($out['refund']->total())->toBe(20)
        ->and($out['cargoLost']->total())->toBe(40)
        ->and($profile->cargo()->total())->toBe(30)
        ->and($profile->fleet())->toHaveCount(2)
        ->and(array_slice($profile->log(), -1)[0]->data)->toMatchArray(['decision' => 'scrap', 'refund' => $out['refund']->toArray()]);
});

it('odrzuca nieznaną zdobycz i drugą decyzję o tej samej', function () {
    $profile = prizeProfile();
    $id = $profile->prizes()[0]->id;
    $profile->decidePrize($id, PrizeDecision::Join);

    expect(fn () => $profile->decidePrize($id, PrizeDecision::Scrap))->toThrow(DomainException::class, 'Prize not found');
});

it('zdobycz czeka w snapshotcie profilu', function () {
    $mapper = new ProfileSnapshotMapper();
    $profile = prizeProfile(ShipType::Niszczyciel, ShipVariant::Zwiadowczy);

    $restored = $mapper->toDomain((string) $profile->id(), $mapper->toArray($profile));

    expect(array_map(static fn ($p) => $p->toArray(), $restored->prizes()))
        ->toBe(array_map(static fn ($p) => $p->toArray(), $profile->prizes()));
});
