<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\ResearchBlock;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\TechBranch;
use App\Domain\Expedition\TechLevel;
use App\Domain\Expedition\Technology;
use App\Domain\Game\Weapon\Loadout;
use App\Domain\Game\Weapon\WeaponKind;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

// Niszczyciel zwiadowczy (#99): wariant niszczyciela z 2 sonarami zamiast
// torpedy, budowany po zbadaniu technologii (Uzbrojenie, poziom 2).

/**
 * Kapitan przy Zatoce Rozbitka z magazynem na niszczyciel.
 *
 * @param list<Technology> $researched
 * @param list<OwnedShip>  $fleet
 */
function scoutCaptain(array $researched = [], array $fleet = [], ?Resources $stock = null, array $battles = []): CaptainProfile
{
    return CaptainProfile::fromSnapshot(
        new ProfileId('0b1c2d3e-4f50-4617-8a9b-0c1d2e3f4a5b'), 'Jacek', 80, $battles, $fleet, Resources::none(),
        stockpiles: ['zatoka-rozbitka' => $stock ?? new Resources(wood: 100, steel: 100)],
        researched: $researched,
    );
}

it('niszczyciel ma warianty torpedowy i zwiadowczy, pozostałe typy żadnych', function () {
    expect(ShipVariant::forType(ShipType::Niszczyciel))->toBe([ShipVariant::Torpedowy, ShipVariant::Zwiadowczy]);
    foreach ([ShipType::Tratwa, ShipType::Kuter, ShipType::Lotniskowiec, ShipType::Transportowiec] as $type) {
        expect(ShipVariant::forType($type))->toBe([])
            ->and(ShipVariant::defaultFor($type))->toBeNull();
    }
    expect(ShipVariant::defaultFor(ShipType::Niszczyciel))->toBe(ShipVariant::Torpedowy);
});

it('torpedowy niesie 1 torpedę, zwiadowczy 2 sonary i 0 torped', function () {
    expect(ShipVariant::Torpedowy->loadout()->toArray())->toBe(['torpedo' => 1])
        ->and(ShipVariant::Torpedowy->loadout()->equals(Loadout::defaultFor(3)))->toBeTrue()
        ->and(ShipVariant::Zwiadowczy->loadout()->usesOf(WeaponKind::Sonar))->toBe(2)
        ->and(ShipVariant::Zwiadowczy->loadout()->usesOf(WeaponKind::Torpedo))->toBe(0);
});

it('wariant statku z planszy bitwy rozpoznaje się po długości i wyposażeniu', function () {
    expect(ShipVariant::ofBattleShip(3, Loadout::of(WeaponKind::Sonar, 2)))->toBe(ShipVariant::Zwiadowczy)
        ->and(ShipVariant::ofBattleShip(3, Loadout::defaultFor(3)))->toBe(ShipVariant::Torpedowy)
        ->and(ShipVariant::ofBattleShip(2, Loadout::defaultFor(2)))->toBeNull()
        ->and(ShipVariant::ofBattleShip(3, Loadout::of(WeaponKind::Sonar, 1)))->toBeNull();
});

it('technologia to poziom 2 Uzbrojenia: wymaga Sonaru dalekiego zasięgu i rangi sternik', function () {
    $tech = Technology::NiszczycielZwiadowczy;
    expect($tech->branch())->toBe(TechBranch::Uzbrojenie)
        ->and($tech->level())->toBe(TechLevel::Second)
        ->and($tech->level()->treeBlocker($tech->branch(), [], Rank::Sternik))->toBe(ResearchBlock::Prerequisite)
        ->and($tech->level()->treeBlocker($tech->branch(), [Technology::SonarDalekiegoZasiegu], Rank::Marynarz))->toBe(ResearchBlock::Rank)
        ->and($tech->level()->treeBlocker($tech->branch(), [Technology::SonarDalekiegoZasiegu], Rank::Sternik))->toBeNull();
});

it('statek floty: niszczyciel bez wariantu jest torpedowy, inne typy bez wariantu', function () {
    expect(OwnedShip::build(ShipType::Niszczyciel)->variant)->toBe(ShipVariant::Torpedowy)
        ->and(OwnedShip::build(ShipType::Niszczyciel, ShipVariant::Zwiadowczy)->variant)->toBe(ShipVariant::Zwiadowczy)
        ->and(OwnedShip::build(ShipType::Kuter)->variant)->toBeNull();
    expect(fn () => OwnedShip::build(ShipType::Kuter, ShipVariant::Zwiadowczy))
        ->toThrow(InvalidArgumentException::class, 'Ship variant not available for this ship type');
});

it('bez technologii stocznia buduje tylko niszczyciel torpedowy', function () {
    $profile = scoutCaptain();

    expect(fn () => $profile->buildShip(ShipType::Niszczyciel, 'zatoka-rozbitka', ShipVariant::Zwiadowczy))
        ->toThrow(DomainException::class, 'Ship variant locked: requires technology niszczyciel-zwiadowczy');
    // odmowa nie pobiera surowców
    expect($profile->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 100, 'steel' => 100, 'gold' => 0]);

    expect($profile->buildShip(ShipType::Niszczyciel, 'zatoka-rozbitka')->variant)->toBe(ShipVariant::Torpedowy)
        ->and($profile->buildShip(ShipType::Niszczyciel, 'zatoka-rozbitka', ShipVariant::Torpedowy)->variant)->toBe(ShipVariant::Torpedowy);
});

it('zwiadowca kosztuje i remontuje się jak zwykły niszczyciel, także z Dokiem remontowym', function () {
    $profile = scoutCaptain([Technology::SonarDalekiegoZasiegu, Technology::NiszczycielZwiadowczy]);

    $scout = $profile->buildShip(ShipType::Niszczyciel, 'zatoka-rozbitka', ShipVariant::Zwiadowczy);
    expect($scout->variant)->toBe(ShipVariant::Zwiadowczy)
        ->and($profile->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 70, 'steel' => 80, 'gold' => 0]);

    expect(fn () => $profile->buildShip(ShipType::Kuter, 'zatoka-rozbitka', ShipVariant::Zwiadowczy))
        ->toThrow(InvalidArgumentException::class, 'Ship variant not available for this ship type');

    $docked = scoutCaptain([Technology::DokRemontowy]);
    expect($docked->repairCost(ShipType::Niszczyciel)->toArray())->toBe(['wood' => 5, 'steel' => 5, 'gold' => 0]);
});

it('snapshot profilu: wariant zostaje, stary niszczyciel jest torpedowy, inne typy bez klucza', function () {
    $mapper = new ProfileSnapshotMapper();
    $profile = scoutCaptain(fleet: [
        new OwnedShip('a1', ShipType::Niszczyciel, variant: ShipVariant::Zwiadowczy),
        new OwnedShip('a2', ShipType::Niszczyciel),
        new OwnedShip('a3', ShipType::Kuter),
    ]);

    $state = $mapper->toArray($profile);
    expect($state['fleet'])->toBe([
        ['id' => 'a1', 'type' => 'niszczyciel', 'damaged' => false, 'variant' => 'zwiadowczy'],
        ['id' => 'a2', 'type' => 'niszczyciel', 'damaged' => false, 'variant' => 'torpedowy'],
        ['id' => 'a3', 'type' => 'kuter', 'damaged' => false],
    ]);
    $restored = $mapper->toDomain('0b1c2d3e-4f50-4617-8a9b-0c1d2e3f4a5b', $state)->fleet();
    expect(array_map(static fn (OwnedShip $s) => $s->variant, $restored))->toBe([ShipVariant::Zwiadowczy, ShipVariant::Torpedowy, null]);

    // profil sprzed wariantów (#99): niszczyciel bez klucza, a śmieciowy wariant przy kutrze się pomija
    $state['fleet'] = [
        ['id' => 'b1', 'type' => 'niszczyciel', 'damaged' => true],
        ['id' => 'b2', 'type' => 'kuter', 'damaged' => false, 'variant' => 'zwiadowczy'],
    ];
    $old = $mapper->toDomain('0b1c2d3e-4f50-4617-8a9b-0c1d2e3f4a5b', $state)->fleet();
    expect($old[0]->variant)->toBe(ShipVariant::Torpedowy)
        ->and($old[0]->isDamaged())->toBeTrue()
        ->and($old[1]->variant)->toBeNull();
});

it('zatopiony zwiadowca uszkadza zwiadowcę, a nie niszczyciel torpedowy z tego samego składu', function () {
    $gameId = new GameId('7c9e6679-7425-40de-944b-e07fc1f90ae7');
    $battle = ['island' => 'wyspa-sygnalowa', 'settled' => false, 'result' => null, 'ships' => ['t1', 's1']];
    $fleet = static fn () => [
        new OwnedShip('t1', ShipType::Niszczyciel),
        new OwnedShip('s1', ShipType::Niszczyciel, variant: ShipVariant::Zwiadowczy),
    ];

    $profile = scoutCaptain(fleet: $fleet(), battles: [(string) $gameId => $battle]);
    $outcome = $profile->settleBattle($gameId, 'won', 10, sunkLengths: [3], sunkVariants: [0 => ShipVariant::Zwiadowczy]);
    expect($outcome['damaged'])->toBe(['niszczyciel'])
        ->and(array_map(static fn (OwnedShip $s) => [$s->id, $s->isDamaged()], $profile->fleet()))->toBe([['t1', false], ['s1', true]]);

    // przegrana: tracony jest zwiadowca, torpedowy zostaje we flocie
    $profile = scoutCaptain(fleet: $fleet(), battles: [(string) $gameId => $battle]);
    $profile->settleBattle($gameId, 'lost', 10, sunkLengths: [3], sunkVariants: [0 => ShipVariant::Zwiadowczy]);
    expect(array_map(static fn (OwnedShip $s) => $s->id, $profile->fleet()))->toBe(['t1']);

    // bez wariantu (stara ścieżka) stratę ponosi pierwszy statek tej długości
    $profile = scoutCaptain(fleet: $fleet(), battles: [(string) $gameId => $battle]);
    $profile->settleBattle($gameId, 'won', 10, sunkLengths: [3]);
    expect(array_map(static fn (OwnedShip $s) => [$s->id, $s->isDamaged()], $profile->fleet()))->toBe([['t1', true], ['s1', false]]);
});
