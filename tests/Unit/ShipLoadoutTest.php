<?php

declare(strict_types=1);

use App\Application\Game\OpponentTurn;
use App\Application\Game\WeaponUseDecider;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\DeterministicFleetGenerator;
use App\Domain\Game\Direction;
use App\Domain\Game\FleetComposition;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Game\Weapon\Loadout;
use App\Domain\Game\Weapon\WeaponKind;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use Tests\Support\FleetFactory;

// Wyposażenie per statek (#95): statek długości 3 z sonarem zamiast torpedy
// (zalążek niszczyciela zwiadowczego z #99). Flota: 2 niszczyciele + 2 tratwy,
// jeden z niszczycieli niesie 2 sonary.

/** @return array<int,list<Loadout>> */
function scoutVariants(): array
{
    return [3 => [Loadout::of(WeaponKind::Sonar, 2)]];
}

function scoutRuleset(): FunRuleset
{
    return new FunRuleset(new BoardSize(10, 10), [3 => 2, 1 => 2], null, scoutVariants());
}

/** @return Ship[] zwiadowca (0,0)-(2,0), niszczyciel (0,2)-(2,2), tratwy (5,5) i (7,7) */
function scoutFleet(): array
{
    return [
        new Ship(new Coordinate(0, 0), Orientation::H, 3, Loadout::of(WeaponKind::Sonar, 2)),
        new Ship(new Coordinate(0, 2), Orientation::H, 3),
        new Ship(new Coordinate(5, 5), Orientation::H, 1),
        new Ship(new Coordinate(7, 7), Orientation::H, 1),
    ];
}

function scoutGame(): Game
{
    $game = Game::create(scoutRuleset());
    $game->placeFleet(scoutFleet());
    $game->placeOpponentFleet((new DeterministicFleetGenerator())->generate($game->ruleset()));

    return $game;
}

function alwaysUseWeapons(): WeaponUseDecider
{
    return new class implements WeaponUseDecider {
        public function decide(int $percent): bool
        {
            return true;
        }
    };
}

it('domyślne wyposażenie wynika z długości statku', function () {
    expect(Loadout::defaultFor(1)->toArray())->toBe([])
        ->and(Loadout::defaultFor(2)->toArray())->toBe(['sonar' => 1])
        ->and(Loadout::defaultFor(3)->toArray())->toBe(['torpedo' => 1])
        ->and(Loadout::defaultFor(4)->toArray())->toBe(['airRaid' => 1])
        ->and(Loadout::defaultFor(5)->toArray())->toBe([])
        ->and((new Ship(new Coordinate(0, 0), Orientation::H, 3))->hasDefaultLoadout())->toBeTrue();
});

it('odrzuca nieznaną broń i złą liczbę użyć w wyposażeniu', function (array $raw) {
    Loadout::fromArray($raw);
})->with([
    'nieznana broń' => [['laser' => 1]],
    'zero użyć' => [['sonar' => 0]],
    'za dużo użyć' => [['sonar' => 6]],
    'nie liczba' => [['sonar' => '2']],
])->throws(InvalidArgumentException::class);

it('limity broni liczą się z wyposażenia: sonar zwiadowcy, o jedną torpedę mniej', function () {
    $standard = new FunRuleset(new BoardSize(10, 10), [3 => 2, 1 => 2]);

    expect($standard->weapons()->limits())
        ->toBe(['torpedo' => 2, 'sonar' => 0, 'airRaid' => 0, 'torpedoDiagonal' => 1])
        ->and(scoutRuleset()->weapons()->limits())
        ->toBe(['torpedo' => 1, 'sonar' => 2, 'airRaid' => 0, 'torpedoDiagonal' => 1]);
});

it('wariant we flocie klasycznej zmienia tylko jego broń', function () {
    $rules = new FunRuleset(variants: scoutVariants());

    expect($rules->weapons()->limits())
        ->toBe(['torpedo' => 1, 'sonar' => 5, 'airRaid' => 1, 'torpedoDiagonal' => 1]);
});

it('ruleset odrzuca warianty spoza składu floty', function (array $variants) {
    new FunRuleset(new BoardSize(10, 10), [3 => 1, 1 => 2], null, $variants);
})->with([
    'długość spoza składu' => [[4 => [Loadout::of(WeaponKind::Sonar)]]],
    'więcej wariantów niż statków' => [[3 => [Loadout::of(WeaponKind::Sonar), Loadout::of(WeaponKind::Sonar)]]],
])->throws(InvalidArgumentException::class, 'Ship variants exceed fleet composition');

it('sonar zwiadowcy działa, dopóki ten statek pływa', function () {
    $game = scoutGame();

    expect($game->sonarPing(new Coordinate(5, 5)))->not->toBe([])
        ->and($game->weaponsState()['sonar'])->toBe(['used' => 1, 'limit' => 2, 'radius' => 3]);

    // zatopienie zwiadowcy (0,0)-(2,0) odbiera sonar, choć zostało jedno użycie
    foreach ([[0, 0], [1, 0], [2, 0]] as [$x, $y]) {
        $game->fireOpponentShot(new Coordinate($x, $y));
    }

    expect(fn () => $game->sonarPing(new Coordinate(5, 5)))
        ->toThrow(DomainException::class, 'Sonar requires an unsunk scout ship');
    expect($game->weaponsState()['sonar']['used'])->toBe(1);
});

it('torpeda startuje tylko z nośnika torped, nie ze zwiadowcy tej samej długości', function () {
    $game = scoutGame();

    expect(fn () => $game->fireTorpedo(new Coordinate(0, 0), Direction::S))
        ->toThrow(DomainException::class, 'Torpedo must be launched from an unsunk destroyer');

    expect($game->fireTorpedo(new Coordinate(0, 2), Direction::E))->not->toBe([])
        ->and($game->weaponsState()['torpedo'])->toBe(['used' => 1, 'limit' => 1]);
});

it('flota musi zgadzać się ze składem także co do wyposażenia', function () {
    // bez wariantu w rulesecie: zwiadowca nie pasuje do składu…
    $plain = Game::create(new FunRuleset(new BoardSize(10, 10), [3 => 2, 1 => 2]));
    expect(fn () => $plain->placeFleet(scoutFleet()))
        ->toThrow(DomainException::class, 'Invalid fleet composition');

    // …a z wariantem w rulesecie nie pasują dwa zwykłe niszczyciele
    $fleet = scoutFleet();
    $fleet[0] = new Ship(new Coordinate(0, 0), Orientation::H, 3);
    expect(fn () => Game::create(scoutRuleset())->placeFleet($fleet))
        ->toThrow(DomainException::class, 'Invalid fleet composition');
});

it('lustrzany wróg dostaje warianty wyposażenia (D6 w #54)', function () {
    $game = scoutGame();

    expect($game->opponentWeaponsState()['sonar']['limit'])->toBe(2)
        ->and($game->opponentWeaponsState()['torpedo']['limit'])->toBe(1)
        ->and($game->opponentHasWeaponCarrier(WeaponKind::Sonar))->toBeTrue()
        // wyrzutnie torped wroga to tylko komórki jego zwykłego niszczyciela
        ->and($game->opponentLaunchableCells())->toHaveCount(3);

    $scouts = array_filter($game->opponentFleet(), static fn (Ship $s) => $s->loadout->carries(WeaponKind::Sonar));
    expect($scouts)->toHaveCount(1);
});

it('przy pełnej banderze wróg traci najpierw statek z wyposażeniem domyślnym', function () {
    $composition = [3 => 2, 1 => 2];
    $rules = new FunRuleset(new BoardSize(10, 10), $composition, FleetComposition::withoutLargest($composition), scoutVariants());

    expect($rules->opponentVariants())->toEqual(scoutVariants())
        ->and($rules->opponentWeapons()->limits())
        ->toBe(['torpedo' => 0, 'sonar' => 2, 'airRaid' => 0, 'torpedoDiagonal' => 0]);
});

it('sonar AI wymaga jego żywego nośnika sonaru, także zwiadowcy', function () {
    $game = scoutGame();

    // zwiadowca wroga z deterministycznego generatora: pierwszy statek l3
    $scout = array_values(array_filter($game->opponentFleet(), static fn (Ship $s) => $s->loadout->carries(WeaponKind::Sonar)))[0];
    foreach ($scout->cells() as $c) {
        $game->fireShot($c);
    }
    expect($game->opponentHasWeaponCarrier(WeaponKind::Sonar))->toBeFalse();

    (new OpponentTurn(alwaysUseWeapons()))->respond($game);

    expect($game->opponentWeaponsState()['sonar']['used'])->toBe(0);
});

it('classic z wariantem nie daje broni, ale pilnuje składu', function () {
    $rules = new ClassicRuleset(new BoardSize(10, 10), [3 => 2, 1 => 2], null, scoutVariants());
    $game = Game::create($rules);
    $game->placeFleet(scoutFleet());

    expect($rules->weapons()->limits())->toBe(['torpedo' => 0, 'sonar' => 0, 'airRaid' => 0, 'torpedoDiagonal' => 0]);
});

it('snapshot zachowuje wyposażenie statków i warianty składu', function () {
    $game = scoutGame();
    $m = new GameSnapshotMapper();

    $arr = $m->toArray($game);
    expect($arr['fleet'][0]['w'])->toBe(['sonar' => 2])
        ->and($arr['fleet'][1])->not->toHaveKey('w')
        ->and($arr['ruleset']['variants'])->toBe([3 => [['sonar' => 2]]])
        ->and($arr['ruleset']['opponentVariants'])->toBeNull();

    // JSON zamienia klucze długości na stringi — jak przy zapisie w bazie
    $restored = $m->toDomain((string) $game->id(), json_decode(json_encode($arr), true));

    expect($restored->weaponsState())->toBe($game->weaponsState())
        ->and($restored->opponentWeaponsState())->toBe($game->opponentWeaponsState())
        ->and($restored->fleet()[0]->loadout->toArray())->toBe(['sonar' => 2])
        ->and($restored->opponentLaunchableCells())->toBe($game->opponentLaunchableCells());
});

it('snapshot gry bez wariantów nie zmienia kształtu, a stary snapshot dostaje wyposażenie domyślne', function () {
    $game = Game::create(new FunRuleset());
    $game->placeFleet(FleetFactory::classic10x10());
    $m = new GameSnapshotMapper();

    $arr = $m->toArray($game);
    foreach ($arr['fleet'] as $ship) {
        expect($ship)->toHaveKeys(['x', 'y', 'o', 'l'])->not->toHaveKey('w');
    }
    expect($arr['ruleset']['variants'])->toBeNull();

    // kształt sprzed #95: bez kluczy variants/opponentVariants i bez `w`
    unset($arr['ruleset']['variants'], $arr['ruleset']['opponentVariants']);
    $restored = $m->toDomain((string) $game->id(), $arr);

    expect($restored->weaponsState())->toBe($game->weaponsState());
    foreach ($restored->fleet() as $ship) {
        expect($ship->hasDefaultLoadout())->toBeTrue();
    }
});
