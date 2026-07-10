<?php

declare(strict_types=1);

namespace Tests\Domain\Game;

use App\Domain\Game\Board;
use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\DeterministicFleetGenerator;
use App\Domain\Game\RandomFleetGenerator;
use App\Domain\Game\Ruleset;
use App\Domain\Game\Ship;
use PHPUnit\Framework\TestCase;

final class FleetGeneratorTest extends TestCase
{
    /** @param Ship[] $ships */
    private function assertValidFleet(array $ships, Ruleset $ruleset): void
    {
        // kompozycja zgodna z rulesetem
        $got = [];
        foreach ($ships as $ship) {
            $got[$ship->length] = ($got[$ship->length] ?? 0) + 1;
        }
        $expected = $ruleset->allowedShips();
        ksort($got);
        ksort($expected);
        self::assertSame($expected, $got, 'Fleet composition does not match ruleset');

        // rozstawienie poprawne: brak kolizji/styku, w granicach planszy
        $board = new Board($ruleset->boardSize());
        $board->placeMany($ships); // rzuci DomainException przy błędnym układzie
        self::assertCount(count($ships), $board->ships());
    }

    public function testDeterministicGeneratorReturnsValidClassicFleet(): void
    {
        $ruleset = new ClassicRuleset(new BoardSize(10, 10));
        $this->assertValidFleet((new DeterministicFleetGenerator())->generate($ruleset), $ruleset);
    }

    public function testRandomGeneratorReturnsValidFleetEveryTime(): void
    {
        $ruleset = new ClassicRuleset(new BoardSize(10, 10));
        $generator = new RandomFleetGenerator();

        for ($i = 0; $i < 25; ++$i) {
            $this->assertValidFleet($generator->generate($ruleset), $ruleset);
        }
    }

    public function testRandomGeneratorSupportsRectangularBoard(): void
    {
        $ruleset = new ClassicRuleset(new BoardSize(12, 10));
        $generator = new RandomFleetGenerator();

        for ($i = 0; $i < 10; ++$i) {
            $this->assertValidFleet($generator->generate($ruleset), $ruleset);
        }
    }

    public function testRandomGeneratorProducesDifferentLayouts(): void
    {
        $ruleset = new ClassicRuleset(new BoardSize(10, 10));
        $generator = new RandomFleetGenerator();

        $key = static fn (array $ships): string => implode('|', array_map(
            static fn (Ship $s) => "{$s->start->x}:{$s->start->y}:{$s->orientation->value}:{$s->length}",
            $ships
        ));

        $layouts = [];
        for ($i = 0; $i < 10; ++$i) {
            $layouts[$key($generator->generate($ruleset))] = true;
        }

        // 10 losowań i wszystkie identyczne = generator nie losuje (fallback/bug)
        self::assertGreaterThan(1, count($layouts), 'Random generator returned identical layouts');
    }
}
