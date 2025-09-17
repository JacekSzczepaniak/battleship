<?php


declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\Game;
use PHPUnit\Framework\TestCase;
use Tests\Support\FleetFactory;

final class GameFireTorpedoTest extends TestCase
{
    public function testFireTorpedoAcrossEntireRow(): void
    {
        $rules = new ClassicRuleset();
        $game = Game::create($rules);
        $fleet = FleetFactory::classic10x10();
        $game->placeFleet($fleet);

        // Torpeda od (0,0) na wschód przez cały wiersz
        $results = $game->fireTorpedo(new Coordinate(0, 0), Direction::E);

        $this->assertCount(10, $results);
        foreach ($results as $i => $r) {
            $this->assertSame($i, $r['x']);
            $this->assertSame(0, $r['y']);
            $this->assertContains($r['result'], ['hit', 'sunk', 'miss', 'duplicate']);
        }

        // Ponowne odpalenie — wszystkie powinny być duplicate
        $resultsDup = $game->fireTorpedo(new Coordinate(0, 0), Direction::E);
        foreach ($resultsDup as $r) {
            $this->assertSame('duplicate', $r['result']);
        }
    }

    public function testFireTorpedoNorthFromCenter(): void
    {
        $rules = new ClassicRuleset();
        $game = Game::create($rules);
        $fleet = FleetFactory::classic10x10();
        $game->placeFleet($fleet);

        // Z (5, 9) w górę — 10 pól (y: 9..0)
        $results = $game->fireTorpedo(new Coordinate(5, 9), Direction::N);

        $this->assertCount(10, $results);
        foreach ($results as $i => $r) {
            $this->assertSame(5, $r['x']);
            $this->assertSame(9 - $i, $r['y']);
            $this->assertContains($r['result'], ['hit', 'sunk', 'miss', 'duplicate']);
        }
    }
}
