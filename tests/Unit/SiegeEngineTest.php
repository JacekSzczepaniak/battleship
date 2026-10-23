<?php

declare(strict_types=1);

use App\Application\Game\CreateGame;
use App\Application\Game\OpponentTurn;
use App\Application\Game\WeaponUseDecider;
use App\Domain\Game\BoardSize;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\GameStatus;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Domain\Game\ShotResult;
use App\Domain\Game\Siege\Fort;
use App\Domain\Game\Siege\FortGenerator;
use App\Domain\Game\Siege\FortPiece;
use App\Domain\Game\Siege\FortRules;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use App\Infrastructure\Persistence\InMemory\InMemoryGameRepository;
use Tests\Support\FleetFactory;

// Silnik oblężenia (#119): fort z działami, murami i magazynem prochu.

/**
 * Fort ręczny: działa (0,0) (9,0) (0,9) (6,4); mury (3,2)–(5,2), (8,6)–(8,7),
 * (1,6)–(2,6); magazyn (5,3) na styk z murem — eksplozja (4..6, 2..4)
 * trafia mur w (4,2) i (5,2) oraz działo (6,4).
 */
function siegeFort(): Fort
{
    return new Fort(
        7,
        [new Coordinate(0, 0), new Coordinate(9, 0), new Coordinate(0, 9), new Coordinate(6, 4)],
        [
            new Ship(new Coordinate(3, 2), Orientation::H, 3),
            new Ship(new Coordinate(8, 6), Orientation::V, 2),
            new Ship(new Coordinate(1, 6), Orientation::H, 2),
        ],
        new Coordinate(5, 3),
    );
}

function siegeGame(?Fort $fort = null): Game
{
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFort($fort ?? siegeFort());
    $game->placeFleet(FleetFactory::classic10x10());

    return $game;
}

/** @return list<string> */
function fortCells(Fort $fort): array
{
    $out = [];
    foreach ($fort->toShips() as $ship) {
        foreach ($ship->cells() as $c) {
            $out[] = $c->x.':'.$c->y;
        }
    }

    return $out;
}

it('układ fortu jest powtarzalny dla tego samego seeda', function () {
    $gen = new FortGenerator();
    expect($gen->generate(42)->toArray())->toBe($gen->generate(42)->toArray())
        ->and($gen->generate(42)->toArray())->not->toBe($gen->generate(43)->toArray());
});

it('fort z generatora ma liczby z FortRules, mieści się na planszy i nie nakłada konstrukcji', function () {
    $gen = new FortGenerator();
    foreach (range(0, 60) as $seed) {
        $fort = $gen->generate($seed * 7919);
        expect($fort->guns)->toHaveCount(FortRules::GUNS)
            ->and($fort->walls)->toHaveCount(FortRules::WALLS);
        foreach ($fort->walls as $wall) {
            expect($wall->length)->toBeGreaterThanOrEqual(FortRules::WALL_MIN_LENGTH)
                ->toBeLessThanOrEqual(FortRules::WALL_MAX_LENGTH);
        }

        $cells = fortCells($fort);
        expect(array_unique($cells))->toHaveCount(count($cells));
        foreach ($cells as $cell) {
            [$x, $y] = array_map('intval', explode(':', $cell));
            expect($x)->toBeLessThan(FortRules::SIZE)->and($y)->toBeLessThan(FortRules::SIZE);
        }

        // magazyn stoi przy murze, więc eksplozja zawsze coś trafia
        $blast = array_map(static fn (Coordinate $c) => $fort->pieceAt($c->x, $c->y), $fort->blastCells(10, 10));
        expect($blast)->toContain(FortPiece::Wall);
    }
});

it('trafienie muru odsłania mur, ale nie daje wygranej', function () {
    $game = siegeGame();
    // cały mur (1,6)–(2,6)
    expect($game->fireShot(new Coordinate(1, 6)))->toBe(ShotResult::Hit)
        ->and($game->fireShot(new Coordinate(2, 6)))->toBe(ShotResult::Sunk)
        ->and($game->status())->toBe(GameStatus::InProgress)
        ->and($game->fortGunsLeft())->toBe(4)
        ->and($game->isFinished())->toBeFalse();
});

it('zniszczenie ostatniego działa daje wygraną, choć mury i magazyn stoją', function () {
    $game = siegeGame();
    foreach ([[0, 0], [9, 0], [0, 9]] as [$x, $y]) {
        $game->fireShot(new Coordinate($x, $y));
    }
    expect($game->fortGunsLeft())->toBe(1)->and($game->status())->toBe(GameStatus::InProgress);

    expect($game->fireShot(new Coordinate(6, 4)))->toBe(ShotResult::Sunk)
        ->and($game->fortGunsLeft())->toBe(0)
        ->and($game->status())->toBe(GameStatus::Won)
        ->and($game->isFinished())->toBeTrue()
        ->and($game->fortExploded())->toBeFalse();
});

it('trafienie magazynu wywołuje eksplozję 3×3, która trafia działa i mury', function () {
    $game = siegeGame();
    expect($game->fireShot(new Coordinate(5, 3)))->toBe(ShotResult::Sunk)
        ->and($game->fortExploded())->toBeTrue();

    $shots = array_map(static fn (array $s) => $s['x'].':'.$s['y'], $game->shots());
    foreach ([4, 5, 6] as $x) {
        foreach ([2, 3, 4] as $y) {
            expect($shots)->toContain("$x:$y");
        }
    }
    expect($shots)->toHaveCount(9);

    $results = [];
    foreach ($game->shotsWithResults() as $s) {
        $results[$s['x'].':'.$s['y']] = $s['result'];
    }
    expect($results['6:4'])->toBe('sunk')   // działo zniszczone
        ->and($results['4:2'])->toBe('hit')  // mur trafiony, (3,2) stoi
        ->and($results['5:5'] ?? null)->toBeNull()
        ->and($results['4:3'])->toBe('miss')
        ->and($game->fortGunsLeft())->toBe(3);
});

it('eksplozja przy krawędzi jest przycięta do planszy', function () {
    $fort = new Fort(1, [new Coordinate(9, 9), new Coordinate(1, 0)], [new Ship(new Coordinate(0, 1), Orientation::V, 2)], new Coordinate(0, 0));
    $game = siegeGame($fort);
    $game->fireShot(new Coordinate(0, 0));

    expect($game->shots())->toHaveCount(4)
        ->and($game->fortGunsLeft())->toBe(1);
});

it('fort oddaje jeden zwykły strzał na turę, bez broni specjalnych i energii', function () {
    $always = new class implements WeaponUseDecider {
        public function decide(int $percent): bool
        {
            return true;
        }
    };
    $game = siegeGame();
    $game->fireShot(new Coordinate(3, 3)); // pudło

    $out = (new OpponentTurn($always))->respond($game);

    expect($out['opponentMoves'])->toHaveCount(1)
        ->and($out['opponentTorpedoLaunch'])->toBeNull()
        ->and($game->opponentShots())->toHaveCount(1)
        ->and($game->opponentWeaponsState()['sonar']['used'])->toBe(0)
        ->and($out['turn'])->toBe('player');
});

it('fort przestaje strzelać po utracie ostatniego działa', function () {
    $game = siegeGame();
    foreach ([[0, 0], [9, 0], [0, 9], [6, 4]] as [$x, $y]) {
        $game->fireShot(new Coordinate($x, $y));
    }

    $out = (new OpponentTurn())->respond($game);

    expect($out['opponentMoves'])->toBe([])
        ->and($out['win'])->toBeTrue()
        ->and($game->opponentShots())->toBe([]);
});

it('snapshot zachowuje fort, konstrukcje na styk i stan dział', function () {
    $game = siegeGame();
    $game->fireShot(new Coordinate(5, 3));
    $game->fireShot(new Coordinate(0, 0));

    $mapper = new GameSnapshotMapper();
    $json = json_decode(json_encode($mapper->toArray($game)), true);
    $restored = $mapper->toDomain((string) $game->id(), $json);

    expect($restored->fort()?->toArray())->toBe(siegeFort()->toArray())
        ->and($restored->fortGunsLeft())->toBe(2)
        ->and($restored->fortExploded())->toBeTrue()
        ->and($restored->shotsWithResults())->toBe($game->shotsWithResults());

    // zwykła gra nie ma klucza fortu
    $plain = Game::create(new FunRuleset(new BoardSize(10, 10)));
    expect($mapper->toArray($plain)['fort'])->toBeNull();
});

it('CreateGame z seedem fortu tworzy bitwę oblężniczą 10×10 z zasadami fun', function () {
    $game = (new CreateGame(new InMemoryGameRepository()))->handle(14, 14, 'classic', fortSeed: 5);

    expect($game->ruleset()->name())->toBe('fun')
        ->and($game->ruleset()->boardSize()->width)->toBe(FortRules::SIZE)
        ->and($game->fort()?->toArray())->toBe((new FortGenerator())->generate(5)->toArray())
        ->and($game->opponentFleet())->toHaveCount(FortRules::GUNS + FortRules::WALLS + FortRules::MAGAZINES);
});
