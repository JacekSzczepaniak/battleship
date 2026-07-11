<?php

declare(strict_types=1);

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use App\Domain\Game\Weapon\TorpedoSpec;
use Tests\Support\FleetFactory;

function funGameForSpecs(): Game
{
    $game = Game::create(new FunRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10());

    return $game;
}

it('fun ruleset ma pełne specyfikacje broni', function () {
    $weapons = (new FunRuleset())->weapons();

    expect($weapons->torpedo->uses)->toBe(2)
        ->and($weapons->torpedo->diagonalUses)->toBe(1)
        ->and($weapons->sonar->uses)->toBe(3)
        ->and($weapons->sonar->radius)->toBe(3)
        ->and($weapons->airRaid->uses)->toBe(1)
        ->and($weapons->airRaid->maxArea->width)->toBe(3)
        ->and($weapons->airRaid->maxArea->height)->toBe(3);
});

it('classic ruleset nie ma broni specjalnych', function () {
    expect((new ClassicRuleset())->weapons()->limits())->toBe([
        'torpedo' => 0,
        'sonar' => 0,
        'airRaid' => 0,
        'torpedoDiagonal' => 0,
    ]);
});

it('odrzuca spec torpedy z pulą przekątnych większą niż pula użyć', function () {
    new TorpedoSpec(uses: 1, diagonalUses: 2);
})->throws(InvalidArgumentException::class, 'Diagonal uses exceed torpedo uses');

it('odrzuca sonar o promieniu ponad spec z rulesetu', function () {
    funGameForSpecs()->sonarPing(new Coordinate(5, 5), 4);
})->throws(DomainException::class, 'Sonar radius exceeds ruleset limit');

it('sonar o promieniu ponad spec nie zużywa użycia', function () {
    $game = funGameForSpecs();

    try {
        $game->sonarPing(new Coordinate(5, 5), 4);
    } catch (DomainException) {
        // ponad limit z rulesetu — oczekiwane
    }

    expect($game->weaponsState()['sonar']['used'])->toBe(0);
});

it('sonar bez podanego promienia skanuje pełnym promieniem z rulesetu', function () {
    // środek planszy: pełny krzyż r=3 → 1 + 4×3 = 13 komórek
    expect(funGameForSpecs()->sonarPing(new Coordinate(5, 5)))->toHaveCount(13);
});

it('sonar z mniejszym promieniem skanuje mniejszy krzyż', function () {
    expect(funGameForSpecs()->sonarPing(new Coordinate(5, 5), 1))->toHaveCount(5);
});
