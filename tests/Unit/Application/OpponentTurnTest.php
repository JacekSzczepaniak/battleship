<?php

declare(strict_types=1);

use App\Application\Game\OpponentTurn;
use App\Application\Game\WeaponUseDecider;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use Tests\Support\FleetFactory;

function decider(callable $fn): WeaponUseDecider
{
    return new class($fn(...)) implements WeaponUseDecider {
        public function __construct(private readonly Closure $fn)
        {
        }

        public function decide(int $percent): bool
        {
            return ($this->fn)($percent);
        }
    };
}

function funGameWithBothFleets(): Game
{
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());
    $game->placeOpponentFleet(FleetFactory::classic10x10());
    $game->setTurn('opponent');

    return $game;
}

it('AI robi zwykły strzał, gdy losowanie odmawia użycia broni', function () {
    $turn = new OpponentTurn(decider(fn (int $pct) => false));
    $game = funGameWithBothFleets();

    $out = $turn->respond($game);

    expect($out['opponentMoves'])->toHaveCount(1);
    expect($game->opponentWeaponsState())->toBe([
        'torpedo' => ['used' => 0, 'limit' => 2],
        'sonar' => ['used' => 0, 'limit' => 3],
        'airRaid' => ['used' => 0, 'limit' => 1],
    ]);
});

it('AI w trybie hunt używa sonaru jako zwiadu i dobija wykryty statek', function () {
    $turn = new OpponentTurn(decider(fn (int $pct) => true));
    $game = funGameWithBothFleets();

    $out = $turn->respond($game);

    // sonar zużyty (zwiad), akcja ofensywna = zwykły strzał w wykryty cel
    expect($game->opponentWeaponsState()['sonar']['used'])->toBe(1);
    expect($game->opponentWeaponsState()['torpedo']['used'])->toBe(0);
    expect($out['opponentMoves'])->toHaveCount(1);
    expect($out['opponentMoves'][0]['result'])->toBeIn(['hit', 'sunk']);

    // wykryte cele przetrwały w snapshotcie stanu AI
    expect($game->aiState()['targets'])->not->toBe([]);
});

it('AI odpala torpedę z niezatopionego statku w linię z nieostrzelanymi polami', function () {
    // decyzja: tak tylko dla torpedy (35%), nie dla sonaru (40%) i nalotu (25%)
    $turn = new OpponentTurn(decider(fn (int $pct) => 35 === $pct));
    $game = funGameWithBothFleets();

    $out = $turn->respond($game);

    expect($game->opponentWeaponsState()['torpedo']['used'])->toBe(1);
    expect(count($out['opponentMoves']))->toBeGreaterThanOrEqual(5);
    // wszystkie strzały torpedy wylądowały na planszy gracza
    expect($game->opponentShots())->toHaveCount(count($out['opponentMoves']));
});

it('AI używa nalotu, gdy torpeda niedostępna', function () {
    // tak dla nalotu (25%), nie dla sonaru; torpeda „tak", ale wyczerpiemy jej limit
    $turn = new OpponentTurn(decider(fn (int $pct) => 40 !== $pct));
    $game = funGameWithBothFleets();
    $game->setWeaponUsesFromSnapshot(['opponent' => ['torpedo' => 2]]); // limit torped wyczerpany

    $out = $turn->respond($game);

    expect($game->opponentWeaponsState()['airRaid']['used'])->toBe(1);
    expect(count($out['opponentMoves']))->toBe(9); // pełne 3×3 w głębi planszy
});

it('AI nie sięga po bronie w trybie target (dobija zwykłym strzałem)', function () {
    $turn = new OpponentTurn(decider(fn (int $pct) => true));
    $game = funGameWithBothFleets();
    $game->setAiState(['targets' => [['x' => 0, 'y' => 0]]]); // tryb target

    $out = $turn->respond($game);

    expect($game->opponentWeaponsState()['sonar']['used'])->toBe(0);
    expect($game->opponentWeaponsState()['torpedo']['used'])->toBe(0);
    expect($out['opponentMoves'])->toHaveCount(1);
    expect($out['opponentMoves'][0])->toMatchArray(['x' => 0, 'y' => 0]);
});

it('AI w grze klasycznej nigdy nie używa broni', function () {
    $turn = new OpponentTurn(decider(fn (int $pct) => true));
    $game = Game::create(new ClassicRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());
    $game->placeOpponentFleet(FleetFactory::classic10x10());
    $game->setTurn('opponent');

    $out = $turn->respond($game);

    expect($out['opponentMoves'])->toHaveCount(1);
    expect($game->weaponUses())->toBe(['player' => [], 'opponent' => []]);
});

it('limity broni gracza i AI są rozdzielne', function () {
    $game = funGameWithBothFleets();
    $game->setWeaponUsesFromSnapshot(['player' => ['torpedo' => 2]]); // gracz wystrzelał swoje

    // AI wciąż ma pełny limit — torpeda przechodzi
    $turn = new OpponentTurn(decider(fn (int $pct) => 35 === $pct));
    $turn->respond($game);

    expect($game->weaponsState()['torpedo']['used'])->toBe(2);
    expect($game->opponentWeaponsState()['torpedo']['used'])->toBe(1);
});
