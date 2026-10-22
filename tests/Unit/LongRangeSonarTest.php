<?php

declare(strict_types=1);

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Weapon\SonarSpec;
use App\Infrastructure\Persistence\Doctrine\Mapper\GameSnapshotMapper;
use Tests\Support\FleetFactory;

function longRangeGame(?int $sonarRadius): Game
{
    $game = Game::create(new FunRuleset(new BoardSize(10, 10), sonarRadius: $sonarRadius));
    $game->placeFleet(FleetFactory::classic10x10());
    $game->placeOpponentFleet(FleetFactory::classic10x10());

    return $game;
}

it('bez technologii sonar obu stron ma promień 3', function () {
    $rules = new FunRuleset(new BoardSize(10, 10));

    expect($rules->sonarRadius())->toBe(SonarSpec::RADIUS)
        ->and($rules->weapons()->sonar->radius)->toBe(3)
        ->and($rules->opponentWeapons()->sonar->radius)->toBe(3);
});

it('promień sonaru gracza nie przechodzi do lustra wroga ani nie zmienia liczby użyć', function () {
    $rules = new FunRuleset(new BoardSize(10, 10), sonarRadius: SonarSpec::LONG_RANGE_RADIUS);

    expect($rules->weapons()->sonar->radius)->toBe(4)
        ->and($rules->opponentWeapons()->sonar->radius)->toBe(3)
        ->and($rules->weapons()->limits())->toBe((new FunRuleset(new BoardSize(10, 10)))->weapons()->limits());
});

it('odrzuca ujemny promień sonaru', function () {
    new FunRuleset(new BoardSize(10, 10), sonarRadius: -1);
})->throws(InvalidArgumentException::class);

it('sonar gracza skanuje krzyż o promieniu 4, a sonar wroga o promieniu 3', function () {
    $game = longRangeGame(SonarSpec::LONG_RANGE_RADIUS);

    expect($game->sonarPing(new Coordinate(5, 5)))->toHaveCount(1 + 4 * 4)
        ->and($game->opponentSonarPing(new Coordinate(5, 5)))->toHaveCount(1 + 4 * 3)
        ->and($game->weaponsState()['sonar'])->toBe(['used' => 1, 'limit' => 3, 'radius' => 4])
        ->and($game->opponentWeaponsState()['sonar'])->toBe(['used' => 1, 'limit' => 3, 'radius' => 3]);
});

it('wróg nie może skanować z promieniem gracza', function () {
    longRangeGame(SonarSpec::LONG_RANGE_RADIUS)->opponentSonarPing(new Coordinate(5, 5), 4);
})->throws(DomainException::class, 'Sonar radius exceeds ruleset limit');

it('snapshot zapamiętuje promień gracza, a bazowego nie zapisuje', function () {
    $mapper = new GameSnapshotMapper();
    $long = longRangeGame(SonarSpec::LONG_RANGE_RADIUS);

    $arr = $mapper->toArray($long);
    $restored = $mapper->toDomain((string) $long->id(), $arr);

    expect($arr['ruleset']['sonarRadius'])->toBe(4)
        ->and($restored->ruleset()->weapons()->sonar->radius)->toBe(4)
        ->and($restored->ruleset()->opponentWeapons()->sonar->radius)->toBe(3)
        ->and($mapper->toArray(longRangeGame(null))['ruleset']['sonarRadius'])->toBeNull()
        ->and($mapper->toArray(Game::create(new ClassicRuleset()))['ruleset']['sonarRadius'])->toBeNull();
});

it('snapshot sprzed technologii wraca z promieniem 3', function () {
    $mapper = new GameSnapshotMapper();
    $arr = $mapper->toArray(longRangeGame(SonarSpec::LONG_RANGE_RADIUS));
    unset($arr['ruleset']['sonarRadius']);

    expect($mapper->toDomain('00000000-0000-4000-8000-000000000097', $arr)->ruleset()->weapons()->sonar->radius)->toBe(3);
});
