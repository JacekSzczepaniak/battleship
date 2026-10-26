<?php

declare(strict_types=1);

use App\Application\Expedition\CaptainBattlePerks;
use App\Application\Game\CreateGame;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\Specializations;
use App\Domain\Expedition\SpecializationSource;
use App\Domain\Game\BattlePerks;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;
use App\Infrastructure\Persistence\InMemory\InMemoryGameRepository;

// Perki ścieżki bojowej (#161, Epic K): remont po wygranej −20% (1), próg abordażu −1 (2),
// +1 sonar (3), łup +20% (4), +1⚡ w pierwszej turze (5). Wróg ich nie dostaje (D6).

/** Wygranych do poziomu ścieżki bojowej (5 pkt za wygraną, progi 20/60/120/200/300). */
const COMBAT_LEVEL_WINS = [0 => 0, 1 => 4, 2 => 12, 3 => 24, 4 => 40, 5 => 60];

/**
 * Kapitan z danym poziomem ścieżki bojowej, niszczycielem i surowcami w magazynie Zatoki.
 *
 * @param list<OwnedShip>|null $fleet
 */
function combatCaptain(int $level, ?array $fleet = null): CaptainProfile
{
    return CaptainProfile::fromSnapshot(
        id: CaptainProfile::create('Jacek')->id(),
        name: 'Jacek',
        xp: 0,
        battles: [],
        fleet: $fleet ?? [OwnedShip::build(ShipType::Niszczyciel)],
        cargo: Resources::none(),
        tick: 10,
        stockpiles: ['zatoka-rozbitka' => new Resources(wood: 100, steel: 100, gold: 100)],
        specializations: (new Specializations())->add(SpecializationSource::Win, COMBAT_LEVEL_WINS[$level]),
    );
}

/** Wygrana bitwa o Zatokę, w której zatonął statek gracza o tej długości (wynik: uszkodzenie). */
function winWithDamage(CaptainProfile $profile, int $length, ?Resources $loot = null): array
{
    $gameId = GameId::new();
    $profile->startBattle('zatoka-rozbitka', $gameId, array_map(static fn (OwnedShip $s) => $s->id, $profile->fleet()));

    return $profile->settleBattle($gameId, 'won', 10, $loot ?? Resources::none(), [$length]);
}

/** Bitwa fun 10×10: gracz kuter (sonar) i niszczyciel, wróg to samo. */
function perkGame(BattlePerks $perks, string $firstTurn = 'player'): Game
{
    $game = (new CreateGame(new InMemoryGameRepository()))->handle(10, 10, 'fun', [3 => 1, 2 => 1], $firstTurn, boarding: true, perks: $perks);
    $game->placeFleet([
        new Ship(new Coordinate(5, 5), Orientation::H, 3),
        new Ship(new Coordinate(9, 0), Orientation::V, 2),
    ]);
    $game->placeOpponentFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 3),
        new Ship(new Coordinate(0, 8), Orientation::H, 2),
    ]);

    return $game;
}

it('perki bitwy działają od swojego poziomu i nie działają poniżej', function () {
    $of = static fn (int $level) => CaptainBattlePerks::of(combatCaptain($level));

    expect($of(1))->toEqual(new BattlePerks())
        ->and($of(2))->toEqual(new BattlePerks(boardingEdge: 1))
        ->and($of(3))->toEqual(new BattlePerks(extraSonarUses: 1, boardingEdge: 1))
        ->and($of(4))->toEqual(new BattlePerks(extraSonarUses: 1, boardingEdge: 1))
        ->and($of(5))->toEqual(new BattlePerks(extraSonarUses: 1, firstTurnEnergy: 1, boardingEdge: 1));
});

it('remont statku uszkodzonego w wygranej bitwie kosztuje 20% mniej od poziomu 1', function () {
    $novice = combatCaptain(0);
    $veteran = combatCaptain(1);
    winWithDamage($novice, 3);
    winWithDamage($veteran, 3);
    [$noviceShip] = $novice->fleet();
    [$veteranShip] = $veteran->fleet();

    expect($veteranShip->isBattleDamaged())->toBeTrue()
        ->and($novice->repairCostOf($noviceShip))->toEqual(new Resources(wood: 10, steel: 10))
        ->and($veteran->repairCostOf($veteranShip))->toEqual(new Resources(wood: 8, steel: 8));

    $veteran->repairShip($veteranShip->id, 'zatoka-rozbitka');

    expect($veteran->stockpile('zatoka-rozbitka'))->toEqual(new Resources(wood: 92, steel: 92, gold: 100))
        ->and($veteranShip->isDamaged())->toBeFalse()
        ->and($veteranShip->isBattleDamaged())->toBeFalse();
});

it('zniżka nie obejmuje uszkodzenia spoza bitwy (sztorm, wyprawa lądowa)', function () {
    $stormDamaged = new OwnedShip('s1', ShipType::Niszczyciel, true);
    $profile = combatCaptain(5, [$stormDamaged]);

    expect($profile->repairCostOf($stormDamaged))->toEqual(new Resources(wood: 10, steel: 10));
});

it('prawo do zniżki nie zależy od kosztu: tratwa uszkodzona w wygranej ma zniżkę, choć remont jest za darmo (#168)', function () {
    $raft = new OwnedShip('t1', ShipType::Tratwa, damaged: true, battleDamage: true);
    $stormRaft = new OwnedShip('t2', ShipType::Tratwa, damaged: true);
    $veteran = combatCaptain(1, [$raft, $stormRaft]);
    $novice = combatCaptain(0, [$raft]);

    expect($veteran->hasRepairDiscount($raft))->toBeTrue()
        ->and($veteran->repairCostOf($raft))->toEqual(Resources::none())
        ->and($veteran->hasRepairDiscount($stormRaft))->toBeFalse()
        ->and($novice->hasRepairDiscount($raft))->toBeFalse();
});

it('zniżka liczy się po Doku remontowym, z zaokrągleniem w dół, i obejmuje remont floty i kuter', function () {
    $kuter = new OwnedShip('k1', ShipType::Kuter, true, battleDamage: true);
    $profile = combatCaptain(1, [$kuter, new OwnedShip('n1', ShipType::Niszczyciel, true, battleDamage: true)]);

    // kuter 8 drewna → 6 (6,4 w dół), niszczyciel 10/10 → 8/8
    $repair = $profile->repairFleet('zatoka-rozbitka', 3);

    expect($repair->paid)->toEqual(new Resources(wood: 14, steel: 8))
        ->and($profile->repairQueue())->toBe([]);
});

it('znacznik uszkodzenia w bitwie przechodzi przez snapshot profilu', function () {
    $profile = combatCaptain(1);
    winWithDamage($profile, 3);
    $mapper = new ProfileSnapshotMapper();
    $restored = $mapper->toDomain((string) $profile->id(), $mapper->toArray($profile));

    expect($restored->fleet()[0]->isBattleDamaged())->toBeTrue()
        ->and($restored->repairCostOf($restored->fleet()[0]))->toEqual(new Resources(wood: 8, steel: 8));
});

it('łup z bitwy rośnie o 20% (w górę) od poziomu 4, a poniżej nie', function () {
    $below = combatCaptain(3);
    $perk = combatCaptain(4);
    $loot = new Resources(wood: 28, steel: 10, gold: 3);

    $withoutPerk = winWithDamage($below, 99, $loot);
    $withPerk = winWithDamage($perk, 99, $loot);

    expect($withoutPerk['loot'])->toEqual($loot)
        ->and($withoutPerk['lootBonus'])->toEqual(Resources::none())
        ->and($withPerk['lootBonus'])->toEqual(new Resources(wood: 6, steel: 2, gold: 1))
        ->and($withPerk['loot'])->toEqual(new Resources(wood: 34, steel: 12, gold: 4))
        ->and($perk->stockpile('zatoka-rozbitka'))->toEqual(new Resources(wood: 134, steel: 112, gold: 104));
});

it('perk sonaru dokłada jedno użycie graczowi z nośnikiem sonaru, wrogowi nie', function () {
    $game = perkGame(new BattlePerks(extraSonarUses: 1));

    expect($game->weaponsState()['sonar'])->toBe(['used' => 0, 'limit' => 2, 'radius' => 3, 'bonus' => 1])
        ->and($game->opponentWeaponsState()['sonar'])->toBe(['used' => 0, 'limit' => 1, 'radius' => 3])
        ->and(perkGame(BattlePerks::none())->weaponsState()['sonar'])->toBe(['used' => 0, 'limit' => 1, 'radius' => 3]);

    $game->sonarPing(new Coordinate(4, 4));
    $game->sonarPing(new Coordinate(4, 4));

    expect(fn () => $game->sonarPing(new Coordinate(4, 4)))->toThrow(DomainException::class, 'Sonar limit reached');
});

it('bez nośnika sonaru perk nie daje sonaru', function () {
    $rules = new FunRuleset(new BoardSize(10, 10), [3 => 1], sonarBonus: 1);

    expect($rules->weapons()->sonar->uses)->toBe(0)
        ->and($rules->sonarBonusApplied())->toBe(0);
});

it('perk energii daje +1⚡ tylko w pierwszej turze gracza, wrogowi nic', function () {
    $game = perkGame(new BattlePerks(firstTurnEnergy: 1));

    expect($game->energyState())->toMatchArray(['left' => 4, 'max' => 4, 'bonus' => 1])
        ->and($game->energyLeft('opponent'))->toBe(3);

    $game->passTurnTo('opponent');
    $game->passTurnTo('player');

    expect($game->energyState())->toMatchArray(['left' => 3, 'max' => 3, 'bonus' => 0]);
});

it('gdy pierwszą turę ma wróg (obrona), premia czeka na pierwszą turę gracza', function () {
    $game = perkGame(new BattlePerks(firstTurnEnergy: 1), 'opponent');

    expect($game->energyLeft('opponent'))->toBe(3)
        ->and($game->firstTurnEnergyBonus())->toBe(0);

    $game->passTurnTo('opponent');
    $game->passTurnTo('player');

    expect($game->energyState())->toMatchArray(['left' => 4, 'max' => 4, 'bonus' => 1]);
});

it('perk abordażu obniża próg o 1 i widać go na liście modyfikatorów', function () {
    $game = perkGame(new BattlePerks(boardingEdge: 1));

    // kilka statków wroga: bez celu, sam perk
    expect($game->boardingState())->toMatchArray(['threshold' => 4, 'modifiers' => [['code' => 'captain', 'value' => -1]]]);

    $game->fireShot(new Coordinate(0, 8));
    $game->fireShot(new Coordinate(1, 8));
    $game->fireShot(new Coordinate(0, 0));
    $game->fireShot(new Coordinate(1, 0));

    // ostatni statek z połową kadłuba trafioną: 5 − 1 − 1
    expect($game->boardingState())->toMatchArray([
        'threshold' => 3,
        'modifiers' => [['code' => 'half-hull', 'value' => -1], ['code' => 'captain', 'value' => -1]],
    ])->and($game->attemptBoarding()->threshold)->toBe(3);
});

it('bitwa bez perków (trening) zostaje przy zasadach bazowych', function () {
    $game = perkGame(BattlePerks::none());

    expect($game->energyState())->toMatchArray(['left' => 3, 'max' => 3, 'bonus' => 0])
        ->and($game->boardingState())->toMatchArray(['threshold' => 5, 'modifiers' => []])
        ->and($game->weaponsState()['sonar'])->not->toHaveKey('bonus');
});

it('perki bitwy przechodzą przez snapshot gry', function () {
    $game = perkGame(new BattlePerks(extraSonarUses: 1, firstTurnEnergy: 1, boardingEdge: 1));
    $mapper = new GameSnapshotMapper();
    $restored = $mapper->toDomain((string) $game->id(), $mapper->toArray($game));

    expect($restored->weaponsState()['sonar'])->toBe(['used' => 0, 'limit' => 2, 'radius' => 3, 'bonus' => 1])
        ->and($restored->energyState())->toMatchArray(['left' => 4, 'max' => 4, 'bonus' => 1])
        ->and($restored->boardingState())->toMatchArray(['threshold' => 4]);
});
