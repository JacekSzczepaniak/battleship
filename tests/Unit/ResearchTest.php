<?php

declare(strict_types=1);

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Island;
use App\Domain\Expedition\Rank;
use App\Domain\Expedition\ResearchBlock;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\StaticIslandCatalog;
use App\Domain\Expedition\TechBranch;
use App\Domain\Expedition\TechLevel;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;

function academyIsland(string $id): Island
{
    return (new StaticIslandCatalog())->byId($id) ?? throw new LogicException("No island $id");
}

/**
 * Kapitan z flotą startową (kuter: ładownia 15) zakotwiczony przy wyspie,
 * domyślnie na Mieliznach pod banderą, z uczelnią.
 *
 * @param list<string>                                                                      $owned
 * @param array<string, list<BuildingType>>|null                                            $buildings
 * @param list<Technology>                                                                  $researched
 * @param array{technology: Technology, island: string, progress: int, countedTo: int}|null $research
 * @param array<string, mixed>|null                                                         $counterattacks
 *
 * @return array{0: CaptainProfile, 1: WorldMap}
 */
function scholar(
    string $at = 'mielizny',
    array $owned = ['mielizny'],
    ?Resources $stock = null,
    ?Resources $cargo = null,
    ?array $buildings = null,
    int $tick = 0,
    int $xp = 40,
    array $researched = [],
    ?array $research = null,
    ?array $counterattacks = null,
    array $stockpiles = [],
): array {
    $id = CaptainProfile::create('Jacek')->id();
    $seed = crc32((string) $id) & 0x7FFFFFFF;
    $islands = (new StaticIslandCatalog())->all();
    $world = WorldMap::generate($seed, array_map(static fn ($i) => $i->id, $islands), $islands);

    $profile = CaptainProfile::fromSnapshot(
        $id, 'Jacek', $xp, [], null, $cargo ?? Resources::none(), $seed, $world->islandPosition($at),
        tick: $tick,
        stockpiles: $stockpiles + [$at => $stock ?? new Resources(100, 100, 100)],
        depositTicks: array_fill_keys($owned, 0),
        ownedIslands: array_fill_keys($owned, 0),
        counterattacks: $counterattacks,
        buildings: $buildings ?? ['mielizny' => [BuildingType::Uczelnia]],
        researched: $researched,
        research: $research,
    );

    return [$profile, $world];
}

it('uczelnia kosztuje 30 drewna, 20 stali i 15 złota i stoi także w Zatoce Rozbitka', function () {
    expect(BuildingType::Uczelnia->buildCost()->toArray())->toBe(['wood' => 30, 'steel' => 20, 'gold' => 15])
        ->and(BuildingType::Uczelnia->fitsSite(academyIsland('zatoka-rozbitka')))->toBeTrue()
        ->and(BuildingType::Uczelnia->fitsSite(academyIsland('mielizny')))->toBeTrue();

    [$profile, $world] = scholar(at: 'zatoka-rozbitka', owned: ['zatoka-rozbitka'], buildings: []);
    $profile->buildBuilding(BuildingType::Uczelnia, academyIsland('zatoka-rozbitka'), $world);

    expect($profile->hasActiveBuilding('zatoka-rozbitka', BuildingType::Uczelnia))->toBeTrue();
});

it('katalog zawiera tylko działające technologie, a poziom niesie koszt, czas i rangę', function () {
    expect(Technology::cases())->toBe([Technology::Spichlerze, Technology::MapySztormowe, Technology::SonarDalekiegoZasiegu, Technology::ZeglugaDwusektorowa, Technology::PojemneLadownie, Technology::DokRemontowy, Technology::LepszeNarzedzia])
        ->and(Technology::Spichlerze->branch())->toBe(TechBranch::Osady)
        ->and(Technology::MapySztormowe->branch())->toBe(TechBranch::Nawigacja)
        ->and(Technology::Spichlerze->level())->toBe(TechLevel::First)
        ->and(TechLevel::First->researchCost()->toArray())->toBe(['wood' => 20, 'steel' => 20, 'gold' => 5])
        ->and(TechLevel::First->researchTicks())->toBe(6)
        ->and(TechLevel::First->requiredRank())->toBeNull()
        ->and(TechLevel::Second->researchCost()->toArray())->toBe(['wood' => 40, 'steel' => 30, 'gold' => 15])
        ->and(TechLevel::Second->researchTicks())->toBe(10)
        ->and(TechLevel::Second->requiredRank())->toBe(Rank::Sternik);
});

it('poziom 2 wymaga zbadanego poziomu 1 w tej samej gałęzi i rangi sternik', function () {
    expect(TechLevel::Second->treeBlocker(TechBranch::Osady, [], Rank::Admiral))->toBe(ResearchBlock::Prerequisite)
        ->and(TechLevel::Second->treeBlocker(TechBranch::Osady, [Technology::MapySztormowe], Rank::Admiral))->toBe(ResearchBlock::Prerequisite)
        ->and(TechLevel::Second->treeBlocker(TechBranch::Osady, [Technology::Spichlerze], Rank::Bosman))->toBe(ResearchBlock::Rank)
        ->and(TechLevel::Second->treeBlocker(TechBranch::Osady, [Technology::Spichlerze], Rank::Sternik))->toBeNull()
        ->and(TechLevel::First->treeBlocker(TechBranch::Kadluby, [], Rank::Rozbitek))->toBeNull()
        ->and(ResearchBlock::Rank->message(Technology::Spichlerze))->toStartWith('Technology locked');
});

it('start badania płaci najpierw z magazynu wyspy, resztę z ładowni, i nie kosztuje tiku', function () {
    [$profile, $world] = scholar(stock: new Resources(wood: 15, steel: 20, gold: 5), cargo: new Resources(wood: 8));

    $profile->startResearch(Technology::Spichlerze, academyIsland('mielizny'), $world);

    expect($profile->research())->toBe(['technology' => Technology::Spichlerze, 'island' => 'mielizny', 'progress' => 0, 'countedTo' => 0])
        ->and($profile->researchTicksLeft())->toBe(6)
        ->and($profile->stockpile('mielizny')->toArray())->toBe(['wood' => 0, 'steel' => 0, 'gold' => 0])
        ->and($profile->cargo()->toArray())->toBe(['wood' => 3, 'steel' => 0, 'gold' => 0])
        ->and($profile->tick())->toBe(0);
});

it('start badania jest odrzucany', function (array $scholar, Technology $technology, string $island, string $message) {
    [$profile, $world] = scholar(...$scholar);
    $stockBefore = $profile->stockpile($island);

    expect(fn () => $profile->startResearch($technology, academyIsland($island), $world))
        ->toThrow(DomainException::class, $message);
    expect($profile->stockpile($island))->toEqual($stockBefore);
})->with([
    'wyspa bez uczelni' => [['buildings' => []], Technology::Spichlerze, 'mielizny', 'No active academy on this island'],
    'uczelnia bez bandery' => [['owned' => []], Technology::Spichlerze, 'mielizny', 'No active academy on this island'],
    'kapitan nie stoi na wyspie' => [['at' => 'zatoka-rozbitka', 'stockpiles' => ['mielizny' => new Resources(100, 100, 100)]], Technology::Spichlerze, 'mielizny', 'Not at island'],
    'brak surowców' => [['stock' => new Resources(wood: 20, steel: 20, gold: 4)], Technology::Spichlerze, 'mielizny', 'Not enough resources'],
    'drugie badanie, gdy trwa pierwsze' => [['research' => ['technology' => Technology::Spichlerze, 'island' => 'mielizny', 'progress' => 2, 'countedTo' => 0]], Technology::MapySztormowe, 'mielizny', 'Research already in progress'],
    'technologia już zbadana' => [['researched' => [Technology::Spichlerze]], Technology::Spichlerze, 'mielizny', 'Technology already researched'],
]);

it('naraz trwa jedno badanie, także gdy kapitan ma drugą uczelnię', function () {
    [$profile, $world] = scholar(
        owned: ['mielizny', 'zatoka-rozbitka'],
        buildings: ['mielizny' => [BuildingType::Uczelnia], 'zatoka-rozbitka' => [BuildingType::Uczelnia]],
        stockpiles: ['zatoka-rozbitka' => new Resources(100, 100, 100)],
    );
    $profile->startResearch(Technology::Spichlerze, academyIsland('mielizny'), $world);

    expect($profile->researchBlocker(Technology::MapySztormowe, academyIsland('zatoka-rozbitka'), $world))->toBe(ResearchBlock::Busy);
});

it('badanie kończy się po 6 tikach pod banderą i od tej chwili działa', function () {
    $started = ['technology' => Technology::Spichlerze, 'island' => 'mielizny', 'progress' => 0, 'countedTo' => 0];

    [$profile, $world] = scholar(tick: 5, research: $started);
    $profile->ensureWorldState($world);
    expect($profile->researchTicksLeft())->toBe(1)
        ->and($profile->hasResearched(Technology::Spichlerze))->toBeFalse();

    [$profile, $world] = scholar(tick: 6, research: $started);
    $profile->ensureWorldState($world);
    expect($profile->research())->toBeNull()
        ->and($profile->researched())->toBe([Technology::Spichlerze])
        ->and($profile->researchBlocker(Technology::MapySztormowe, academyIsland('mielizny'), $world))->toBeNull();
});

it('Spichlerze: magazyn złoża mieści 20 tików produkcji zamiast 10', function () {
    $mielizny = academyIsland('mielizny');
    [$bare, $world] = scholar(stock: Resources::none(), tick: 30);
    [$granary, $granaryWorld] = scholar(stock: Resources::none(), tick: 30, researched: [Technology::Spichlerze]);

    $bare->ensureWorldState($world);
    $granary->ensureWorldState($granaryWorld);

    expect($bare->depositCap($mielizny)->wood)->toBe(30)
        ->and($bare->stockpile('mielizny')->wood)->toBe(30)
        ->and($granary->depositCap($mielizny)->wood)->toBe(60)
        ->and($granary->stockpile('mielizny')->wood)->toBe(60);
});

it('Spichlerze podnoszą limit dopiero od tiku ukończenia badania', function () {
    // magazyn pełny (30 drewna), badanie kończy się na tiku 6: do niego złoże stoi, potem 4 tiki po 3 drewna
    $started = ['technology' => Technology::Spichlerze, 'island' => 'mielizny', 'progress' => 0, 'countedTo' => 0];
    [$profile, $world] = scholar(stock: new Resources(wood: 30), tick: 10, research: $started);

    $profile->ensureWorldState($world);

    expect($profile->hasResearched(Technology::Spichlerze))->toBeTrue()
        ->and($profile->stockpile('mielizny')->wood)->toBe(42);
});

it('Mapy sztormowe obniżają szansę sztormu do 10%', function () {
    [$profile] = scholar();
    [$charted] = scholar(researched: [Technology::MapySztormowe]);

    expect($profile->stormChance())->toBe(20)
        ->and($charted->stormChance())->toBe(10);
});

it('z Mapami sztormowymi rzut 10–19 nie daje sztormu', function () {
    [$probe, $world] = scholar(at: 'zatoka-rozbitka', owned: ['zatoka-rozbitka'], buildings: []);
    $seed = $probe->worldSeed();
    // tik rejsu, którego rzut sztormu wypada między nową a starą szansą
    $tick = 0;
    while (!in_array(crc32("{$seed}:".($tick + 1)) % 100, range(10, 19), true)) {
        ++$tick;
    }
    $home = $world->islandPosition('zatoka-rozbitka');
    $target = null;
    foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
        if ($world->isInside($home['x'] + $dx, $home['y'] + $dy)) {
            $target = ['x' => $home['x'] + $dx, 'y' => $home['y'] + $dy];
            break;
        }
    }

    $voyage = static function (array $researched) use ($probe, $home, $tick, $target, $world): ?array {
        $profile = CaptainProfile::fromSnapshot(
            $probe->id(), 'Jacek', 40, [], null, Resources::none(), $probe->worldSeed(), $home,
            tick: $tick, visited: ["{$home['x']}:{$home['y']}"], researched: $researched,
        );

        return $profile->sail($target['x'], $target['y'], $world)['event'];
    };

    expect($voyage([]))->not->toBeNull()
        ->and($voyage([Technology::MapySztormowe]))->toBeNull();
});

it('utrata wyspy z uczelnią wstrzymuje badanie, a odbicie je wznawia', function () {
    // kontratak uderza w Mielizny na tiku 2, kapitan nie rozpoczął obrony
    [$profile, $world] = scholar(
        tick: 5,
        research: ['technology' => Technology::Spichlerze, 'island' => 'mielizny', 'progress' => 0, 'countedTo' => 0],
        counterattacks: ['pending' => ['island' => 'mielizny', 'announcedAt' => 0, 'strikeAt' => 2, 'defense' => null], 'calmFrom' => 0, 'rolledTo' => 0],
    );
    $profile->ensureWorldState($world);

    expect($profile->ownsIsland('mielizny'))->toBeFalse()
        ->and($profile->isResearchPaused())->toBeTrue()
        ->and($profile->researchTicksLeft())->toBe(4)
        ->and($profile->researchBlocker(Technology::MapySztormowe, academyIsland('mielizny'), $world))->toBe(ResearchBlock::Busy);

    // odbicie: bitwa na tiku 6, potem liczą się tylko tiki pod banderą
    $gameId = GameId::new();
    $profile->startBattle('mielizny', $gameId);
    $profile->settleBattle($gameId, 'won', 10);
    expect($profile->ownsIsland('mielizny'))->toBeTrue()
        ->and($profile->isResearchPaused())->toBeFalse()
        ->and($profile->researchTicksLeft())->toBe(4);

    $mapper = new ProfileSnapshotMapper();
    $later = static function (CaptainProfile $p, int $ticks) use ($mapper, $world): CaptainProfile {
        $state = $mapper->toArray($p);
        $state['tick'] += $ticks;
        $next = $mapper->toDomain((string) $p->id(), $state);
        $next->ensureWorldState($world);

        return $next;
    };

    $resumed = $later($profile, 3);
    expect($resumed->researchTicksLeft())->toBe(1);
    expect($later($profile, 4)->researched())->toBe([Technology::Spichlerze]);
});

it('snapshot zachowuje badania, a profil sprzed uczelni wczytuje się bez nich', function () {
    [$profile] = scholar(
        researched: [Technology::MapySztormowe],
        research: ['technology' => Technology::Spichlerze, 'island' => 'mielizny', 'progress' => 2, 'countedTo' => 3],
    );
    $mapper = new ProfileSnapshotMapper();

    $state = $mapper->toArray($profile);
    expect($state['research'])->toBe([
        'researched' => ['mapy-sztormowe'],
        'current' => ['technology' => 'spichlerze', 'island' => 'mielizny', 'progress' => 2, 'countedTo' => 3],
    ]);

    $restored = $mapper->toDomain((string) $profile->id(), $state);
    expect($restored->researched())->toBe([Technology::MapySztormowe])
        ->and($restored->research())->toBe(['technology' => Technology::Spichlerze, 'island' => 'mielizny', 'progress' => 2, 'countedTo' => 3]);

    unset($state['research']);
    $legacy = $mapper->toDomain((string) $profile->id(), $state);
    expect($legacy->researched())->toBe([])
        ->and($legacy->research())->toBeNull();

    $state['research'] = ['researched' => ['atlantydzkie-zagle', 'spichlerze'], 'current' => ['technology' => 'atlantydzkie-zagle', 'island' => 'mielizny']];
    $unknown = $mapper->toDomain((string) $profile->id(), $state);
    expect($unknown->researched())->toBe([Technology::Spichlerze])
        ->and($unknown->research())->toBeNull();
});
