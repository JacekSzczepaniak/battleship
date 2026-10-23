<?php

declare(strict_types=1);

use App\Application\Game\BoardShip;
use App\Application\Game\NeverUseWeaponsDecider;
use App\Application\Game\OpponentTurn;
use App\Domain\Game\BattleAction;
use App\Domain\Game\Boarding\BoardingModifier;
use App\Domain\Game\Boarding\BoardingOdds;
use App\Domain\Game\Boarding\BoardingRoll;
use App\Domain\Game\Boarding\BoardingTarget;
use App\Domain\Game\Boarding\HalfHullHit;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\GameStatus;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Shared\GameId;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use App\Infrastructure\Persistence\InMemory\InMemoryGameRepository;

// Abordaż (#118): ⚡3, tylko ostatni statek wroga, jedna próba na bitwę,
// rzut k6 z seeda gry i numeru tury, próg 5+ (−1 przy połowie pól trafionych).

const BOARDING_GAME_ID = '0b0a7d00-0000-4000-8000-000000000118';

/**
 * Wróg: niszczyciel (3) w (0,0)–(2,0) i tratwa w (0,8). Gracz ma ten sam skład gdzie indziej.
 * $lastShip = tratwa zatopiona, został niszczyciel.
 */
function boardingGame(bool $fun = true, bool $enabled = true, bool $lastShip = true): Game
{
    $size = new BoardSize(10, 10);
    $ships = [3 => 1, 1 => 1];
    $game = Game::fromSnapshot(new GameId(BOARDING_GAME_ID), $fun ? new FunRuleset($size, $ships) : new ClassicRuleset($size, $ships), GameStatus::Pending);
    $game->placeFleet([
        new Ship(new Coordinate(5, 5), Orientation::H, 3),
        new Ship(new Coordinate(9, 9), Orientation::H, 1),
    ]);
    $game->placeOpponentFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 3),
        new Ship(new Coordinate(0, 8), Orientation::H, 1),
    ]);
    if ($enabled) {
        $game->enableBoarding();
    }
    if ($lastShip) {
        $game->fireShot(new Coordinate(0, 8));
    }

    return $game;
}

/** Pierwsza tura (od 1), w której rzut tej gry spełnia warunek. */
function boardingRound(callable $accept): int
{
    for ($round = 1; $round < 200; ++$round) {
        if ($accept(BoardingRoll::d6(BOARDING_GAME_ID, $round))) {
            return $round;
        }
    }
    throw new LogicException('No round matches');
}

it('abordaż kosztuje ⚡3, czyli całą turę', function () {
    expect(BattleAction::Board->cost())->toBe(3)
        ->and(BattleAction::costs())->toHaveKey('board', 3);
});

it('jest niedostępny w bitwie treningowej, w klasyce, przy kilku statkach wroga i bez energii', function () {
    expect(boardingGame(enabled: false)->boardingBlocker())->toBe('Boarding not available')
        ->and(boardingGame(enabled: false)->boardingState())->toBeNull()
        ->and(boardingGame(fun: false)->boardingBlocker())->toBe('Boarding not available')
        ->and(boardingGame(lastShip: false)->boardingBlocker())->toBe('Boarding requires the last enemy ship');

    $tired = boardingGame();
    $tired->spendEnergy('player', BattleAction::Sonar);
    expect($tired->boardingBlocker())->toBe('Not enough energy')
        ->and($tired->boardingState())->toMatchArray(['available' => false, 'blocker' => 'energy']);

    expect(fn () => boardingGame(lastShip: false)->attemptBoarding())
        ->toThrow(DomainException::class, 'Boarding requires the last enemy ship');
});

it('rzut jest powtarzalny dla seeda i tury, a tury dają różne wyniki k6', function () {
    expect(BoardingRoll::d6(BOARDING_GAME_ID, 3))->toBe(BoardingRoll::d6(BOARDING_GAME_ID, 3));

    $faces = [];
    for ($round = 1; $round <= 60; ++$round) {
        $faces[BoardingRoll::d6(BOARDING_GAME_ID, $round)] = true;
    }
    ksort($faces);
    expect(array_keys($faces))->toBe([1, 2, 3, 4, 5, 6]);
});

it('próg 5+ spada o 1, gdy połowa pól statku jest trafiona', function () {
    $game = boardingGame();
    expect($game->boardingState())->toMatchArray(['available' => true, 'threshold' => 5, 'modifiers' => [], 'cost' => 3, 'attempt' => null]);

    $game->fireShot(new Coordinate(0, 0));
    expect($game->boardingState()['threshold'])->toBe(5); // 1 z 3 pól

    $game->fireShot(new Coordinate(1, 0));
    expect($game->boardingState())->toMatchArray(['threshold' => 4, 'modifiers' => [['code' => 'half-hull', 'value' => -1]]]);
});

it('modyfikatory to lista do rozszerzenia; próg nie spada poniżej 2', function () {
    $perk = new class implements BoardingModifier {
        public function code(): string
        {
            return 'perk';
        }

        public function reduction(BoardingTarget $target): int
        {
            return 9;
        }
    };
    $target = new BoardingTarget(new Ship(new Coordinate(0, 0), Orientation::H, 2), 1);
    $odds = new BoardingOdds([new HalfHullHit(), $perk]);

    expect($odds->applied($target))->toBe([['code' => 'half-hull', 'value' => -1], ['code' => 'perk', 'value' => -9]])
        ->and($odds->threshold($target))->toBe(2);
});

it('udany abordaż wygrywa bitwę, a statek wroga zostaje zdobyczą', function () {
    $game = boardingGame();
    $game->setRoundFromSnapshot(boardingRound(static fn (int $roll) => $roll >= 5));

    $attempt = $game->attemptBoarding();

    expect($attempt->succeeded())->toBeTrue()
        ->and($game->status())->toBe(GameStatus::Won)
        ->and($game->isFinished())->toBeTrue()
        ->and($game->boardedShip()?->length)->toBe(3)
        ->and($game->boardingState()['attempt'])->toMatchArray(['success' => true, 'threshold' => 5, 'ship' => 0]);
});

it('nieudany abordaż nic nie zmienia na planszy, a drugiej próby nie ma', function () {
    $game = boardingGame();
    $game->setRoundFromSnapshot(boardingRound(static fn (int $roll) => $roll < 5));

    $attempt = $game->attemptBoarding();

    expect($attempt->succeeded())->toBeFalse()
        ->and($game->status())->toBe(GameStatus::InProgress)
        ->and($game->boardedShip())->toBeNull()
        ->and($game->boardingBlocker())->toBe('Boarding already attempted')
        ->and(fn () => $game->attemptBoarding())->toThrow(DomainException::class, 'Boarding already attempted');
});

it('numer tury rośnie, gdy tura wraca do gracza', function () {
    $game = boardingGame();
    expect($game->round())->toBe(1);
    $game->passTurnTo('opponent');
    $game->passTurnTo('player');
    expect($game->round())->toBe(2);
});

it('stan abordażu i numer tury przechodzą przez snapshot', function () {
    $mapper = new GameSnapshotMapper();
    $game = boardingGame();
    $game->setRoundFromSnapshot(boardingRound(static fn (int $roll) => $roll >= 5));
    $game->attemptBoarding();

    $restored = $mapper->toDomain(BOARDING_GAME_ID, $mapper->toArray($game));

    expect($restored->boardingEnabled())->toBeTrue()
        ->and($restored->round())->toBe($game->round())
        ->and($restored->boardingAttempt()?->toArray())->toBe($game->boardingAttempt()?->toArray())
        ->and($restored->boardedShip()?->length)->toBe(3);

    $training = $mapper->toArray(boardingGame(enabled: false));
    expect($training['boarding'])->toBeNull()
        ->and($mapper->toDomain(BOARDING_GAME_ID, $training)->boardingEnabled())->toBeFalse();
});

it('porażka kończy turę: ruch AI i odnowiona energia; sukces kończy bitwę', function () {
    $repo = new InMemoryGameRepository();
    $board = new BoardShip($repo, new OpponentTurn(new NeverUseWeaponsDecider()));

    $failing = boardingGame();
    $failing->setRoundFromSnapshot(boardingRound(static fn (int $roll) => $roll < 5));
    $repo->save($failing);
    $out = $board(BOARDING_GAME_ID);
    expect($out['boarding']['success'])->toBeFalse()
        ->and($out['turn'])->toBe('player')
        ->and($out['opponentMoves'])->not->toBe([])
        ->and($out['energy']['left'])->toBe(3);

    $winning = boardingGame();
    $winning->setRoundFromSnapshot(boardingRound(static fn (int $roll) => $roll >= 5));
    $repo->save($winning);
    $out = $board(BOARDING_GAME_ID);
    expect($out['boarding']['success'])->toBeTrue()
        ->and($out)->toMatchArray(['finished' => true, 'win' => true, 'turn' => 'none', 'opponentMoves' => []]);
});
