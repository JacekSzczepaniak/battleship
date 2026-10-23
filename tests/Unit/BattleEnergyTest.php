<?php

declare(strict_types=1);

use App\Application\Game\EndTurn;
use App\Application\Game\FireShot;
use App\Application\Game\FireTorpedo;
use App\Application\Game\NeverUseWeaponsDecider;
use App\Application\Game\OpponentTurn;
use App\Application\Game\SonarPing;
use App\Application\Game\WeaponUseDecider;
use App\Domain\Game\BattleAction;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use App\Infrastructure\Persistence\InMemory\InMemoryGameRepository;
use Tests\Support\FleetFactory;

// Energia w bitwie (#59, wariant B): 3⚡ na turę, salwa 1, sonar 1, torpeda 2,
// nalot 3. Atak kończy turę, sonar nie; pula wraca po ruchu przeciwnika.

function energyGame(bool $fun = true): Game
{
    $size = new BoardSize(10, 10);
    $game = Game::create($fun ? new FunRuleset($size) : new ClassicRuleset($size));
    $game->placeFleet(FleetFactory::classic10x10());
    $game->placeOpponentFleet(FleetFactory::classic10x10());

    return $game;
}

/** @return array{0: InMemoryGameRepository, 1: string} */
function energyRepoWith(Game $game): array
{
    $repo = new InMemoryGameRepository();
    $repo->save($game);

    return [$repo, (string) $game->id()];
}

/** Decyzje AI po procencie szansy: sonar 40, torpeda 35, nalot 25. */
function energyDecider(int ...$yes): WeaponUseDecider
{
    return new class($yes) implements WeaponUseDecider {
        /** @param list<int> $yes */
        public function __construct(private readonly array $yes)
        {
        }

        public function decide(int $percent): bool
        {
            return in_array($percent, $this->yes, true);
        }
    };
}

/**
 * Mała flota (lotniskowiec, kuter, tratwa) poza krzyżem pierwszego skanu AI
 * (środek (3,3), promień 3), żeby po sonarze AI zostało w trybie hunt.
 */
function energyScoutGame(): Game
{
    $game = Game::create(new FunRuleset(new BoardSize(10, 10), [4 => 1, 2 => 1, 1 => 1]));
    $game->placeFleet([
        new Ship(new Coordinate(5, 9), Orientation::H, 4),
        new Ship(new Coordinate(9, 0), Orientation::V, 2),
        new Ship(new Coordinate(9, 6), Orientation::H, 1),
    ]);
    $game->placeOpponentFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 4),
        new Ship(new Coordinate(0, 2), Orientation::H, 2),
        new Ship(new Coordinate(0, 4), Orientation::H, 1),
    ]);
    $game->setTurn('opponent');

    return $game;
}

it('koszty działań: salwa 1, sonar 1, torpeda 2, nalot 3, ruch statku 1, abordaż 3', function () {
    expect(BattleAction::costs())->toBe(['shot' => 1, 'sonar' => 1, 'torpedo' => 2, 'airRaid' => 3, 'move' => 1, 'board' => 3]);
});

it('tryb z bronią daje 3⚡ na turę, klasyka jest bez energii', function () {
    expect(energyGame()->energyState())->toBe([
        'left' => 3,
        'max' => 3,
        'costs' => ['shot' => 1, 'sonar' => 1, 'torpedo' => 2, 'airRaid' => 3, 'move' => 1, 'board' => 3],
    ])->and(energyGame(false)->energyState())->toBeNull()
        ->and(energyGame(false)->hasEnergy())->toBeFalse();
});

it('pobiera koszt działania i odrzuca działanie bez energii', function () {
    $game = energyGame();
    $game->spendEnergy('player', BattleAction::Sonar);
    $game->spendEnergy('player', BattleAction::Torpedo);

    expect($game->energyLeft('player'))->toBe(0)
        ->and($game->canAfford('player', BattleAction::Shot))->toBeFalse();

    $game->spendEnergy('player', BattleAction::Shot);
})->throws(DomainException::class, 'Not enough energy');

it('klasyka nie liczy energii: każde działanie jest „na stać”', function () {
    $game = energyGame(false);
    $game->spendEnergy('player', BattleAction::AirRaid);
    $game->spendEnergy('player', BattleAction::AirRaid);

    expect($game->canAfford('player', BattleAction::AirRaid))->toBeTrue();
});

it('oddanie tury odnawia energię tylko stronie, która ją dostaje', function () {
    $game = energyGame();
    $game->spendEnergy('player', BattleAction::Torpedo);
    $game->spendEnergy('opponent', BattleAction::AirRaid);

    $game->passTurnTo('opponent');
    expect($game->energyLeft('opponent'))->toBe(3)
        ->and($game->energyLeft('player'))->toBe(1);

    $game->passTurnTo('player');
    expect($game->energyLeft('player'))->toBe(3);
});

it('snapshot zachowuje zużytą energię, a brak pola oznacza pełną pulę', function () {
    $game = energyGame();
    $game->spendEnergy('player', BattleAction::Sonar);
    $m = new GameSnapshotMapper();

    $arr = $m->toArray($game);
    expect($arr['energySpent'])->toBe(['player' => 1, 'opponent' => 0])
        ->and($m->toDomain((string) $game->id(), $arr)->energyLeft('player'))->toBe(2);

    // gra zapisana przed #59: bez energySpent
    unset($arr['energySpent']);
    expect($m->toDomain((string) $game->id(), $arr)->energyLeft('player'))->toBe(3)
        ->and($m->toArray(energyGame())['energySpent'])->toBeNull();
});

it('sonar zużywa energię i nie kończy tury; kilka skanów w turze, póki starcza energii', function () {
    [$repo, $id] = energyRepoWith(energyGame());
    $sonar = new SonarPing($repo);

    $sonar($id, 5, 5);
    $out = $sonar($id, 2, 7);

    expect($out['energy']['left'])->toBe(1)
        ->and($repo->get(new App\Domain\Shared\GameId($id))->turn())->toBe('player');
});

it('atak kończy turę, a po ruchu AI energia gracza wraca do pełnej puli', function () {
    $game = energyGame();
    [$repo, $id] = energyRepoWith($game);

    (new SonarPing($repo))($id, 5, 5);
    $out = (new FireShot($repo, new OpponentTurn(new NeverUseWeaponsDecider())))->handle($id, 9, 9);

    expect($out['turn'])->toBe('player')
        ->and($out['opponentMoves'])->toHaveCount(1)
        ->and($out['energy']['left'])->toBe(3)
        ->and($game->energyLeft('player'))->toBe(3);
});

it('torpeda po dwóch sonarach jest odrzucona: brak energii', function () {
    [$repo, $id] = energyRepoWith(energyGame());
    (new SonarPing($repo))($id, 5, 5);
    (new SonarPing($repo))($id, 2, 7);

    (new FireTorpedo($repo, new OpponentTurn(new NeverUseWeaponsDecider())))($id, 0, 2, Direction::E);
})->throws(DomainException::class, 'Not enough energy');

it('„Zakończ turę” oddaje turę bez ataku gracza', function () {
    $game = energyGame();
    [$repo, $id] = energyRepoWith($game);
    (new SonarPing($repo))($id, 5, 5);

    $out = (new EndTurn($repo, new OpponentTurn(new NeverUseWeaponsDecider())))($id);

    expect($out['turn'])->toBe('player')
        ->and($out['opponentMoves'])->toHaveCount(1)
        ->and($game->shots())->toBe([])
        ->and($game->energyLeft('player'))->toBe(3);
});

it('„Zakończ turę” jest niedostępne w klasyce', function () {
    [$repo, $id] = energyRepoWith(energyGame(false));

    (new EndTurn($repo, new OpponentTurn(new NeverUseWeaponsDecider())))($id);
})->throws(DomainException::class, 'End turn requires battle energy');

it('„Zakończ turę” wymaga tury gracza', function () {
    $game = energyGame();
    $game->setTurn('opponent');
    [$repo, $id] = energyRepoWith($game);

    (new EndTurn($repo, new OpponentTurn(new NeverUseWeaponsDecider())))($id);
})->throws(DomainException::class, 'Not player turn');

it('sonar wymaga tury gracza', function () {
    $game = energyGame();
    $game->setTurn('opponent');
    [$repo, $id] = energyRepoWith($game);

    (new SonarPing($repo))($id, 5, 5);
})->throws(DomainException::class, 'Not player turn');

it('AI płaci energią: po sonarze nie stać go na nalot, strzela salwą', function () {
    $game = energyScoutGame();

    $out = (new OpponentTurn(energyDecider(40, 25)))->respond($game);

    expect($game->opponentWeaponsState()['sonar']['used'])->toBe(1)
        ->and($game->opponentWeaponsState()['airRaid']['used'])->toBe(0)
        ->and($out['opponentMoves'])->toHaveCount(1)
        ->and($game->energySpent()['opponent'])->toBe(2);
});

it('AI bez sonaru stać na nalot za całą pulę', function () {
    $game = energyScoutGame();

    $out = (new OpponentTurn(energyDecider(25)))->respond($game);

    expect($game->opponentWeaponsState()['airRaid']['used'])->toBe(1)
        ->and($out['opponentMoves'])->toHaveCount(9)
        ->and($game->energySpent()['opponent'])->toBe(3);
});
