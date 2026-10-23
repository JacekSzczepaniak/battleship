<?php

declare(strict_types=1);

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Guardian;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Prize;
use App\Domain\Expedition\PrizeDecision;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\TechUnlock;
use App\Domain\Game\FleetComposition;
use App\Domain\Game\Weapon\Loadout;
use App\Domain\Game\Weapon\WeaponKind;
use App\Domain\Shared\ProfileId;

// Zbadanie konstrukcji zdobytego statku (#121): trzy warianty ma tylko wróg,
// a gracz odblokowuje je, badając zdobycz z abordażu, nie na uczelni.

const STUDY_PRIZE_ID = '5c4a0000-0000-4000-8000-000000000121';

/**
 * Profil z jedną zdobyczą i drewnem w magazynie Zatoki Rozbitka.
 *
 * @param list<Technology> $researched
 */
function studyProfile(ShipType $type, ?ShipVariant $variant, array $researched = []): CaptainProfile
{
    return CaptainProfile::fromSnapshot(
        new ProfileId('7a1c0000-0000-4000-8000-000000000121'), 'Jacek', 0, [],
        [new OwnedShip('k1', ShipType::Kuter)], Resources::none(),
        stockpiles: ['zatoka-rozbitka' => new Resources(wood: 50)],
        researched: $researched,
        prizes: [new Prize(STUDY_PRIZE_ID, $type, $variant, 0)],
    );
}

it('warianty ze zdobyczy mają swój kadłub i wyposażenie', function () {
    expect(ShipVariant::KuterTorpedowy->type())->toBe(ShipType::Kuter)
        ->and(ShipVariant::KuterTorpedowy->loadout()->toArray())->toBe(['torpedo' => 1])
        ->and(ShipVariant::CiezkiNiszczyciel->type())->toBe(ShipType::Niszczyciel)
        ->and(ShipVariant::CiezkiNiszczyciel->loadout()->toArray())->toBe(['torpedo' => 2])
        ->and(ShipVariant::LotniskowiecEskortowy->type())->toBe(ShipType::Lotniskowiec)
        ->and(ShipVariant::LotniskowiecEskortowy->loadout()->toArray())->toBe(['airRaid' => 1, 'sonar' => 1])
        // kuter i lotniskowiec bez wariantu to statek podstawowy
        ->and(ShipVariant::defaultFor(ShipType::Kuter))->toBeNull()
        ->and(ShipVariant::defaultFor(ShipType::Lotniskowiec))->toBeNull()
        ->and(ShipVariant::forType(ShipType::Kuter))->toBe([ShipVariant::KuterTorpedowy])
        ->and(ShipVariant::forType(ShipType::Lotniskowiec))->toBe([ShipVariant::LotniskowiecEskortowy]);
});

it('wariant statku wroga rozpoznaje się po długości i wyposażeniu', function () {
    expect(ShipVariant::ofBattleShip(2, Loadout::of(WeaponKind::Torpedo)))->toBe(ShipVariant::KuterTorpedowy)
        ->and(ShipVariant::ofBattleShip(2, Loadout::defaultFor(2)))->toBeNull()
        ->and(ShipVariant::ofBattleShip(3, Loadout::of(WeaponKind::Torpedo, 2)))->toBe(ShipVariant::CiezkiNiszczyciel)
        ->and(ShipVariant::ofBattleShip(3, Loadout::defaultFor(3)))->toBe(ShipVariant::Torpedowy)
        ->and(ShipVariant::ofBattleShip(4, ShipVariant::LotniskowiecEskortowy->loadout()))->toBe(ShipVariant::LotniskowiecEskortowy)
        ->and(ShipVariant::ofBattleShip(4, Loadout::defaultFor(4)))->toBeNull();
});

it('technologie wariantów ze zdobyczy są poza poziomami drzewka', function () {
    foreach ([ShipVariant::KuterTorpedowy, ShipVariant::CiezkiNiszczyciel, ShipVariant::LotniskowiecEskortowy] as $variant) {
        $technology = $variant->requiredTechnology();
        expect($technology?->unlock())->toBe(TechUnlock::Boarding)
            ->and($technology?->level())->toBeNull();
    }
    expect(Technology::NiszczycielZwiadowczy->unlock())->toBe(TechUnlock::Academy)
        ->and(fn () => Technology::KuterTorpedowy->academyLevel())->toThrow(LogicException::class);
});

it('zbadaj konstrukcję: statek przepada, a wariant od razu trafia do stoczni', function () {
    $profile = studyProfile(ShipType::Kuter, ShipVariant::KuterTorpedowy);
    expect($profile->studyableTechnology($profile->prizes()[0]))->toBe(Technology::KuterTorpedowy);
    $tick = $profile->tick();

    $out = $profile->decidePrize(STUDY_PRIZE_ID, PrizeDecision::Study);

    expect($out['technology'])->toBe(Technology::KuterTorpedowy)
        ->and($out['ship'])->toBeNull()
        ->and($out['refund']->isZero())->toBeTrue()
        ->and($profile->prizes())->toBe([])
        ->and($profile->fleet())->toHaveCount(1)
        ->and($profile->hasResearched(Technology::KuterTorpedowy))->toBeTrue()
        ->and($profile->tick())->toBe($tick)
        ->and(array_slice($profile->log(), -1)[0]->event)->toBe(LogEvent::PrizeDecided)
        ->and(array_slice($profile->log(), -1)[0]->data)->toBe(['ship' => 'kuter', 'variant' => 'kuter-torpedowy', 'decision' => 'study', 'technology' => 'kuter-torpedowy']);

    $ship = $profile->buildShip(ShipType::Kuter, 'zatoka-rozbitka', ShipVariant::KuterTorpedowy);
    expect($ship->variant)->toBe(ShipVariant::KuterTorpedowy);
});

it('bez zbadania wariantu ze zdobyczy stocznia go nie zbuduje', function () {
    $profile = studyProfile(ShipType::Kuter, ShipVariant::KuterTorpedowy);

    expect(fn () => $profile->buildShip(ShipType::Kuter, 'zatoka-rozbitka', ShipVariant::KuterTorpedowy))
        ->toThrow(DomainException::class, 'Ship variant locked: requires technology kuter-torpedowy');
});

it('zbadać można tylko nieznany wariant ze zdobyczy; zdobycz zostaje do innej decyzji', function (ShipType $type, ?ShipVariant $variant, array $researched) {
    $profile = studyProfile($type, $variant, $researched);
    expect($profile->studyableTechnology($profile->prizes()[0]))->toBeNull();

    expect(fn () => $profile->decidePrize(STUDY_PRIZE_ID, PrizeDecision::Study))
        ->toThrow(DomainException::class, 'Prize cannot be studied');
    expect($profile->prizes())->toHaveCount(1);
})->with([
    'statek bez wariantu' => [ShipType::Kuter, null, []],
    'wariant domyślny' => [ShipType::Niszczyciel, ShipVariant::Torpedowy, []],
    'wariant z uczelni' => [ShipType::Niszczyciel, ShipVariant::Zwiadowczy, []],
    'wariant już znany' => [ShipType::Niszczyciel, ShipVariant::CiezkiNiszczyciel, [Technology::CiezkiNiszczyciel]],
]);

it('wariant ze zdobyczy można też dołączyć do floty, nie znając go', function () {
    $profile = studyProfile(ShipType::Lotniskowiec, ShipVariant::LotniskowiecEskortowy);
    expect($profile->rank())->toBe(Rank::Rozbitek);

    $out = $profile->decidePrize(STUDY_PRIZE_ID, PrizeDecision::Join);

    expect($out['ship']?->variant)->toBe(ShipVariant::LotniskowiecEskortowy)
        ->and($out['technology'])->toBeNull()
        ->and($profile->hasResearched(Technology::LotniskowiecEskortowy))->toBeFalse();
});

it('wariant wroga na wyspie jest stały dla świata i wyspy, z puli akwenu', function () {
    $graph = new StaticSeaGraph(new StaticIslandCatalog());
    $ocean = $graph->sea(StaticSeaGraph::STORM_OCEAN);
    $mists = $graph->sea(StaticSeaGraph::MIST_SEA);
    expect($ocean?->enemyVariants)->toBe([ShipVariant::KuterTorpedowy, ShipVariant::CiezkiNiszczyciel])
        ->and($mists?->enemyVariants)->toBe([ShipVariant::LotniskowiecEskortowy])
        ->and($graph->sea(Sea::START)?->enemyVariantFor(42, 'mielizny'))->toBeNull();

    $picked = [];
    foreach (range(1, 20) as $seed) {
        foreach ($ocean->islands->all() as $island) {
            $variant = $ocean->enemyVariantFor($seed, $island->id);
            expect($variant)->toBe($ocean->enemyVariantFor($seed, $island->id));
            $picked[$variant?->value] = true;
        }
        expect($mists->enemyVariantFor($seed, 'dowolna'))->toBe(ShipVariant::LotniskowiecEskortowy);
    }
    // seed rozkłada oba warianty Oceanu między wyspy
    expect(array_keys($picked))->toEqualCanonicalizing(['kuter-torpedowy', 'ciezki-niszczyciel']);
});

it('wariant wroga zajmuje wolny zwykły statek kadłuba, a bez miejsca odpada', function () {
    $torpedoCutter = ShipVariant::KuterTorpedowy->loadout();
    $scout = ShipVariant::Zwiadowczy->loadout();

    // lustro zwiadowcy zostaje, wariant dochodzi na drugi niszczyciel
    expect(FleetComposition::addVariants([3 => [$scout]], [3 => 2, 2 => 1], [3 => [ShipVariant::CiezkiNiszczyciel->loadout()]]))
        ->toEqual([3 => [$scout, ShipVariant::CiezkiNiszczyciel->loadout()]])
        // jedyny niszczyciel jest zwiadowcą: wariant odpada
        ->and(FleetComposition::addVariants([3 => [$scout]], [3 => 1], [3 => [ShipVariant::CiezkiNiszczyciel->loadout()]]))
        ->toEqual([3 => [$scout]])
        // gracz bez kutra: wróg też bez kutra, więc i bez kutra torpedowego
        ->and(FleetComposition::addVariants([], [3 => 1], [2 => [$torpedoCutter]]))->toBe([]);
});

it('strażnik cieśniny pływa z wariantami Oceanu w miejscu zwykłych statków', function () {
    $guardian = (new StaticSeaGraph(new StaticIslandCatalog()))->gate(StaticSeaGraph::GUARDED_STRAIT)?->guardian();

    expect($guardian?->variants)->toBe([ShipVariant::KuterTorpedowy, ShipVariant::CiezkiNiszczyciel])
        ->and($guardian?->loadouts())->toEqual([2 => [ShipVariant::KuterTorpedowy->loadout()], 3 => [ShipVariant::CiezkiNiszczyciel->loadout()]]);

    expect(fn () => new Guardian('X', 'x', Rank::Porucznik, 'fun', [ShipType::Kuter], 10, 5, Resources::none(), Resources::none(), 10, 10, [ShipVariant::LotniskowiecEskortowy]))
        ->toThrow(InvalidArgumentException::class, 'Guardian variants exceed its fleet');
});
