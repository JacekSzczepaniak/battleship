<?php

declare(strict_types=1);

use App\Application\Game\MoveShip;
use App\Domain\Game\BattleAction;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Game\ShotResult;
use App\Domain\Game\Weapon\Loadout;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use App\Infrastructure\Persistence\InMemory\InMemoryGameRepository;

// Ruch statku w bitwie (#60): 1⚡, raz na turę, o 1 pole wzdłuż osi (tratwa
// w 4 strony), tylko statek bez trafienia, nie na pole ostrzelane przez wroga.

/**
 * Flota gracza: niszczyciel (3) poziomo w (2,2)–(4,2), kuter (2) pionowo
 * w (8,0)–(8,1), tratwa w (5,7). Wróg ma ten sam skład gdzie indziej.
 */
function moveGame(bool $fun = true): Game
{
    $size = new BoardSize(10, 10);
    $ships = [3 => 1, 2 => 1, 1 => 1];
    $game = Game::create($fun ? new FunRuleset($size, $ships) : new ClassicRuleset($size, $ships));
    $game->placeFleet([
        new Ship(new Coordinate(2, 2), Orientation::H, 3),
        new Ship(new Coordinate(8, 0), Orientation::V, 2),
        new Ship(new Coordinate(5, 7), Orientation::H, 1),
    ]);
    $game->placeOpponentFleet([
        new Ship(new Coordinate(0, 0), Orientation::H, 3),
        new Ship(new Coordinate(0, 4), Orientation::H, 2),
        new Ship(new Coordinate(0, 8), Orientation::H, 1),
    ]);

    return $game;
}

/** @return list<string> pola statku jako "x:y" */
function moveCells(Ship $ship): array
{
    return array_map(static fn (Coordinate $c) => $c->x.':'.$c->y, $ship->cells());
}

it('ruch kosztuje 1⚡', function () {
    expect(BattleAction::Move->cost())->toBe(1)
        ->and(BattleAction::costs())->toHaveKey('move', 1);
});

it('statek płynie o 1 pole wzdłuż osi, do przodu i do tyłu, wskazany dowolnym polem', function () {
    $game = moveGame();
    $moved = $game->moveShip(new Coordinate(4, 2), Direction::E);
    expect(moveCells($moved))->toBe(['3:2', '4:2', '5:2'])
        ->and(moveCells($game->fleet()[0]))->toBe(['3:2', '4:2', '5:2']);

    $game->passTurnTo('player');
    $moved = $game->moveShip(new Coordinate(8, 1), Direction::S);
    expect(moveCells($moved))->toBe(['8:1', '8:2']);

    $game->passTurnTo('player');
    $moved = $game->moveShip(new Coordinate(3, 2), Direction::W);
    expect(moveCells($moved))->toBe(['2:2', '3:2', '4:2']);
});

it('tratwa płynie w każdą z 4 stron', function (Direction $direction, string $cell) {
    $game = moveGame();
    expect(moveCells($game->moveShip(new Coordinate(5, 7), $direction)))->toBe([$cell]);
})->with([
    'N' => [Direction::N, '5:6'],
    'E' => [Direction::E, '6:7'],
    'S' => [Direction::S, '5:8'],
    'W' => [Direction::W, '4:7'],
]);

it('dłuższy statek nie płynie w poprzek osi ani po skosie', function (Coordinate $cell, Direction $direction) {
    expect(fn () => moveGame()->moveShip($cell, $direction))
        ->toThrow(DomainException::class, 'Ship can only move along its axis');
})->with([
    'poziomy w górę' => [new Coordinate(2, 2), Direction::N],
    'poziomy w dół' => [new Coordinate(2, 2), Direction::S],
    'pionowy w bok' => [new Coordinate(8, 0), Direction::W],
    'tratwa po skosie' => [new Coordinate(5, 7), Direction::NE],
]);

it('statek z trafieniem nie manewruje', function () {
    $game = moveGame();
    expect($game->fireOpponentShot(new Coordinate(3, 2)))->toBe(ShotResult::Hit);

    expect(fn () => $game->moveShip(new Coordinate(2, 2), Direction::W))
        ->toThrow(DomainException::class, 'Damaged ship cannot move');
});

it('nie wolno wpłynąć na pole, w które strzelał przeciwnik', function () {
    $game = moveGame();
    expect($game->fireOpponentShot(new Coordinate(5, 2)))->toBe(ShotResult::Miss);

    expect(fn () => $game->moveShip(new Coordinate(2, 2), Direction::E))
        ->toThrow(DomainException::class, 'Ship cannot move onto a cell already shot');
    // w drugą stronę wolno: pudło obok nie blokuje
    expect(moveCells($game->moveShip(new Coordinate(2, 2), Direction::W)))->toBe(['1:2', '2:2', '3:2']);
});

it('statek nie wypływa poza planszę', function () {
    $game = moveGame();
    expect(fn () => $game->moveShip(new Coordinate(8, 0), Direction::N))
        ->toThrow(DomainException::class, 'Ship move out of board bounds');

    $edge = Game::create(new FunRuleset(new BoardSize(10, 10), [3 => 1]));
    $edge->placeFleet([new Ship(new Coordinate(7, 9), Orientation::H, 3)]);
    expect(fn () => $edge->moveShip(new Coordinate(9, 9), Direction::E))
        ->toThrow(DomainException::class, 'Ship move out of board bounds');
    expect(fn () => $edge->moveShip(new Coordinate(9, 9), Direction::S))
        ->toThrow(DomainException::class, 'Ship can only move along its axis');
});

it('statek nie staje na styk z innym, a odrzucony ruch nie zmienia floty', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10), [3 => 1, 1 => 1]));
    $game->placeFleet([
        new Ship(new Coordinate(2, 2), Orientation::H, 3),
        new Ship(new Coordinate(6, 3), Orientation::H, 1),
    ]);

    // (5,2) styka się po skosie z tratwą w (6,3)
    expect(fn () => $game->moveShip(new Coordinate(2, 2), Direction::E))
        ->toThrow(DomainException::class, 'Ship move would touch another ship');
    expect(moveCells($game->fleet()[0]))->toBe(['2:2', '3:2', '4:2'])
        ->and($game->playerMovedThisTurn())->toBeFalse();
});

it('wskazane pole bez własnego statku jest odrzucane', function () {
    expect(fn () => moveGame()->moveShip(new Coordinate(0, 9), Direction::E))
        ->toThrow(DomainException::class, 'No own ship at this cell');
});

it('drugi ruch w tej samej turze jest odrzucany, po ruchu wroga ruch wraca', function () {
    $game = moveGame();
    $game->moveShip(new Coordinate(5, 7), Direction::N);
    expect($game->playerMovedThisTurn())->toBeTrue();

    expect(fn () => $game->moveShip(new Coordinate(8, 0), Direction::S))
        ->toThrow(DomainException::class, 'Ship already moved this turn');

    $game->passTurnTo('opponent');
    $game->passTurnTo('player');
    expect($game->playerMovedThisTurn())->toBeFalse();
    expect(moveCells($game->moveShip(new Coordinate(8, 0), Direction::S)))->toBe(['8:1', '8:2']);
});

it('tryb klasyczny nie ma ruchu statku', function () {
    expect(fn () => moveGame(false)->moveShip(new Coordinate(5, 7), Direction::N))
        ->toThrow(DomainException::class, 'Ship move not available');
});

it('po ruchu wróg trafia statek na nowych polach, a stare pole jest wodą', function () {
    $game = moveGame();
    $game->moveShip(new Coordinate(5, 7), Direction::E);

    expect($game->fireOpponentShot(new Coordinate(5, 7)))->toBe(ShotResult::Miss)
        ->and($game->fireOpponentShot(new Coordinate(6, 7)))->toBe(ShotResult::Sunk);
});

it('statek zabiera wyposażenie ze sobą', function () {
    $game = Game::create(new FunRuleset(new BoardSize(10, 10), [3 => 1], variants: [3 => [Loadout::fromArray(['sonar' => 2])]]));
    $game->placeFleet([new Ship(new Coordinate(2, 2), Orientation::H, 3, Loadout::fromArray(['sonar' => 2]))]);

    $moved = $game->moveShip(new Coordinate(2, 2), Direction::E);
    expect($moved->loadout->toArray())->toBe(['sonar' => 2])
        ->and($game->fleet()[0]->hasDefaultLoadout())->toBeFalse();
});

it('snapshot zachowuje nową pozycję i wykorzystany ruch', function () {
    $mapper = new GameSnapshotMapper();
    $game = moveGame();
    expect($mapper->toArray($game)['moved'])->toBeNull();

    $game->moveShip(new Coordinate(2, 2), Direction::E);
    $state = $mapper->toArray($game);
    expect($state['moved'])->toBeTrue();

    $restored = $mapper->toDomain((string) $game->id(), json_decode(json_encode($state), true));
    expect($restored->playerMovedThisTurn())->toBeTrue()
        ->and(moveCells($restored->fleet()[0]))->toBe(['3:2', '4:2', '5:2']);
});

it('MoveShip pobiera 1⚡ i zostawia turę graczowi', function () {
    $game = moveGame();
    $repo = new InMemoryGameRepository();
    $repo->save($game);

    $out = (new MoveShip($repo))((string) $game->id(), 5, 7, Direction::W);

    expect($out['ship'])->toBe(['x' => 4, 'y' => 7, 'o' => 'H', 'l' => 1])
        ->and($out['moveUsed'])->toBeTrue()
        ->and($out['energy']['left'])->toBe(2);
    $saved = $repo->get($game->id());
    expect($saved->turn())->toBe('player')
        ->and($saved->energyLeft('player'))->toBe(2);
});

it('MoveShip wymaga energii i tury gracza', function () {
    $game = moveGame();
    $game->spendEnergy('player', BattleAction::AirRaid);
    $repo = new InMemoryGameRepository();
    $repo->save($game);

    expect(fn () => (new MoveShip($repo))((string) $game->id(), 5, 7, Direction::W))
        ->toThrow(DomainException::class, 'Not enough energy');

    $other = moveGame();
    $other->setTurn('opponent');
    $repo->save($other);
    expect(fn () => (new MoveShip($repo))((string) $other->id(), 5, 7, Direction::W))
        ->toThrow(DomainException::class, 'Not player turn');
});
