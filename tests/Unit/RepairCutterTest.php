<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\ResearchBlock;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\TechBranch;
use App\Domain\Expedition\TechLevel;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\TechUnlock;

/**
 * Kapitan z flotą i ładownią, z Dokiem remontowym i Kutrem remontowym (#152).
 * Magazyn Zatoki Rozbitka pozwala odmierzać tiki przeładunkiem złota.
 *
 * @param list<OwnedShip> $fleet
 */
function cutterCaptain(array $fleet, Resources $cargo, bool $fieldRepairs = true): CaptainProfile
{
    return CaptainProfile::fromSnapshot(
        CaptainProfile::create('Jacek')->id(), 'Jacek', 600, [], $fleet, $cargo, 1, null,
        tick: 10,
        stockpiles: ['zatoka-rozbitka' => new Resources(gold: 10)],
        researched: [Technology::DokRemontowy, Technology::KuterRemontowy],
        fieldRepairs: $fieldRepairs,
    );
}

function brokenForCutter(ShipType $type, ?ShipVariant $variant = null): OwnedShip
{
    $ship = OwnedShip::build($type, $variant);
    $ship->markDamaged();

    return $ship;
}

/** Jeden tik: przeładunek złota z magazynu do ładowni. */
function cutterTick(CaptainProfile $profile): void
{
    $profile->loadCargo('zatoka-rozbitka', new Resources(gold: 1));
}

/** @return list<array<string,mixed>> dane wpisów napraw z dziennika */
function fieldRepairLog(CaptainProfile $profile): array
{
    return array_values(array_map(
        static fn (LogEntry $e) => ['tick' => $e->tick] + $e->data,
        array_filter($profile->log(), static fn (LogEntry $e) => LogEvent::FieldRepair === $e->event),
    ));
}

it('kuter remontowy to trzeci poziom Kadłubów: wymaga Doku remontowego i rangi kapitan', function () {
    expect(Technology::KuterRemontowy->branch())->toBe(TechBranch::Kadluby)
        ->and(Technology::KuterRemontowy->level())->toBe(TechLevel::Third)
        ->and(Technology::KuterRemontowy->unlock())->toBe(TechUnlock::Academy)
        ->and(TechLevel::Third->researchCost()->toArray())->toBe(['wood' => 60, 'steel' => 50, 'gold' => 30])
        ->and(TechLevel::Third->researchTicks())->toBe(14)
        ->and(TechLevel::Third->treeBlocker(TechBranch::Kadluby, [Technology::PojemneLadownie], Rank::Kapitan))->toBe(ResearchBlock::Prerequisite)
        ->and(TechLevel::Third->treeBlocker(TechBranch::Kadluby, [Technology::DokRemontowy], Rank::Sternik))->toBe(ResearchBlock::Rank)
        ->and(TechLevel::Third->treeBlocker(TechBranch::Kadluby, [Technology::DokRemontowy], Rank::Kapitan))->toBeNull();
});

it('wariant kutra z warsztatem nie ma broni, nie eskortuje i buduje się dopiero po technologii', function () {
    expect(ShipVariant::KuterRemontowy->type())->toBe(ShipType::Kuter)
        ->and(ShipVariant::KuterRemontowy->loadout()->toArray())->toBe([])
        ->and(ShipVariant::KuterRemontowy->requiredTechnology())->toBe(Technology::KuterRemontowy)
        ->and(OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy)->isArmed())->toBeFalse()
        ->and(OwnedShip::build(ShipType::Kuter)->isArmed())->toBeTrue()
        ->and(CaptainProfile::isUnescortedConvoy([OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy)]))->toBeTrue()
        ->and(CaptainProfile::isUnescortedConvoy([OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Kuter)]))->toBeFalse();

    $stock = ['zatoka-rozbitka' => new Resources(wood: 40)];
    $novice = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 600, [], [], Resources::none(), 1, null, stockpiles: $stock);
    expect(fn () => $novice->buildShip(ShipType::Kuter, 'zatoka-rozbitka', ShipVariant::KuterRemontowy))
        ->toThrow(DomainException::class, 'Ship variant locked: requires technology kuter-remontowy');

    $master = CaptainProfile::fromSnapshot(CaptainProfile::create('Jacek')->id(), 'Jacek', 600, [], [], Resources::none(), 1, null, stockpiles: $stock, researched: [Technology::KuterRemontowy]);
    $cutter = $master->buildShip(ShipType::Kuter, 'zatoka-rozbitka', ShipVariant::KuterRemontowy);
    // koszt jak zwykłego kutra
    expect($cutter->variant)->toBe(ShipVariant::KuterRemontowy)
        ->and($master->stockpile('zatoka-rozbitka')->toArray())->toBe(['wood' => 20, 'steel' => 0, 'gold' => 0]);
});

it('naprawia jeden statek na tik, od najmniejszego, tylko do 3 pól, za koszt z ładowni', function () {
    $niszczyciel = brokenForCutter(ShipType::Niszczyciel);
    $lotniskowiec = brokenForCutter(ShipType::Lotniskowiec);
    $kuter = brokenForCutter(ShipType::Kuter);
    $transportowiec = brokenForCutter(ShipType::Transportowiec);
    $tratwa = brokenForCutter(ShipType::Tratwa);
    $profile = cutterCaptain(
        [$niszczyciel, $lotniskowiec, $kuter, $transportowiec, $tratwa, OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy)],
        new Resources(wood: 20, steel: 10),
    );

    cutterTick($profile);
    expect($tratwa->isDamaged())->toBeFalse()
        ->and($kuter->isDamaged())->toBeTrue();

    cutterTick($profile);
    expect($kuter->isDamaged())->toBeFalse()
        ->and($niszczyciel->isDamaged())->toBeTrue();

    cutterTick($profile);
    cutterTick($profile);
    expect($niszczyciel->isDamaged())->toBeFalse()
        // lotniskowiec i transportowiec czekają na stocznię
        ->and($lotniskowiec->isDamaged())->toBeTrue()
        ->and($transportowiec->isDamaged())->toBeTrue()
        // z Dokiem remontowym: kuter 4 drewna, niszczyciel 5 drewna i 5 stali
        ->and($profile->cargo()->toArray())->toBe(['wood' => 11, 'steel' => 5, 'gold' => 4])
        ->and(fieldRepairLog($profile))->toBe([
            ['tick' => 11, 'ship' => 'tratwa', 'paid' => ['wood' => 0, 'steel' => 0, 'gold' => 0]],
            ['tick' => 12, 'ship' => 'kuter', 'paid' => ['wood' => 4, 'steel' => 0, 'gold' => 0]],
            ['tick' => 13, 'ship' => 'niszczyciel', 'variant' => 'torpedowy', 'paid' => ['wood' => 5, 'steel' => 5, 'gold' => 0]],
        ]);
});

it('bez surowców w ładowni nie naprawia, a statek, na który brakuje, przepuszcza', function () {
    $kuter = brokenForCutter(ShipType::Kuter);
    $niszczyciel = brokenForCutter(ShipType::Niszczyciel);
    // stal na niszczyciel jest, drewna brakuje i na kuter (4), i na niszczyciel (5)
    $profile = cutterCaptain([$kuter, $niszczyciel, OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy)], new Resources(wood: 3, steel: 5));

    cutterTick($profile);
    expect($kuter->isDamaged() && $niszczyciel->isDamaged())->toBeTrue()
        ->and($profile->cargo()->toArray())->toBe(['wood' => 3, 'steel' => 5, 'gold' => 1])
        ->and(fieldRepairLog($profile))->toBe([]);
});

it('każdy sprawny kuter remontowy naprawia jeden statek; uszkodzony nie naprawia, także siebie', function () {
    $broken = brokenForCutter(ShipType::Kuter, ShipVariant::KuterRemontowy);
    $kuter = brokenForCutter(ShipType::Kuter);
    $niszczyciel = brokenForCutter(ShipType::Niszczyciel);
    $alone = cutterCaptain([$broken, $kuter], new Resources(wood: 20));

    cutterTick($alone);
    expect($broken->isDamaged() && $kuter->isDamaged())->toBeTrue();

    $sound = [OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy), OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy)];
    $profile = cutterCaptain([$broken, $niszczyciel, ...$sound], new Resources(wood: 20, steel: 10));

    cutterTick($profile);
    // dwa sprawne kutry: dwie naprawy na tik, także trzeciego kutra remontowego
    expect($broken->isDamaged())->toBeFalse()
        ->and($niszczyciel->isDamaged())->toBeFalse()
        ->and(array_column(fieldRepairLog($profile), 'ship'))->toBe(['kuter', 'niszczyciel']);
});

it('przełącznik wyłącza naprawy i oszczędza ładunek', function () {
    $kuter = brokenForCutter(ShipType::Kuter);
    $profile = cutterCaptain([$kuter, OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy)], new Resources(wood: 20), fieldRepairs: false);

    expect($profile->fieldRepairsEnabled())->toBeFalse();
    cutterTick($profile);
    expect($kuter->isDamaged())->toBeTrue()
        ->and($profile->cargo()->wood)->toBe(20);

    $profile->setFieldRepairs(true);
    cutterTick($profile);
    expect($kuter->isDamaged())->toBeFalse();
});

it('kuter remontowy płynie do bitwy dopiero za nośnikami broni, razem z tratwami', function () {
    $repairer = OwnedShip::build(ShipType::Kuter, ShipVariant::KuterRemontowy);
    $tratwa = OwnedShip::build(ShipType::Tratwa);
    $kuter = OwnedShip::build(ShipType::Kuter);
    $profile = cutterCaptain([$repairer, $tratwa, $kuter], Resources::none());

    expect(array_map(static fn (OwnedShip $s) => $s->id, $profile->battleSquad(null, 2)))->toBe([$kuter->id])
        ->and(array_map(static fn (OwnedShip $s) => $s->id, $profile->battleSquad(null, 5)))->toBe([$kuter->id, $repairer->id, $tratwa->id]);
});
