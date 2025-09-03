<?php

declare(strict_types=1);

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Game;
use Tests\Support\FleetFactory;


it('miss / hit / sunk oraz blokada duplikatów', function () {
    $game = Game::create(new ClassicRuleset(new BoardSize(10, 10)));
    $game->placeFleet(FleetFactory::classic10x10()); // ← najważniejsze: pełna, poprawna flota

    // MISS (woda daleko od floty)
    self::assertSame('miss', $game->fireShot(new Coordinate(9, 9))->value);

    // HIT (traf statek 4-masztowy)
    self::assertSame('hit', $game->fireShot(new Coordinate(0, 0))->value);

    // SUNK (traf jedynkę – zatapia od razu)
    self::assertSame('sunk', $game->fireShot(new Coordinate(0, 6))->value);

    // DUPLICATE (drugi strzał w to samo pole)
    self::assertSame('duplicate', $game->fireShot(new Coordinate(0, 0))->value);
});
