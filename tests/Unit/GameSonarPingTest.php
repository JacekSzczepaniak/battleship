<?php


declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Game;
use PHPUnit\Framework\TestCase;
use Tests\Support\FleetFactory;

final class GameSonarPingTest extends TestCase
{
    public function testSonarCrossFromCornerRespectsBoundsAndReportsOccupancy(): void
    {
        $rules = new ClassicRuleset();
        $game = Game::create($rules);
        $game->placeFleet(FleetFactory::classic10x10());

        $results = $game->sonarPing(new Coordinate(0, 0), 3);

        // Map to quick lookup
        $byKey = [];
        foreach ($results as $r) {
            $byKey[$r['x'] . ':' . $r['y']] = (bool)$r['occupied'];
        }

        // Only in-bounds cells from (0,0) with radius=3 (cross)
        $expected = ['0:0', '1:0', '2:0', '3:0', '0:1', '0:2', '0:3'];
        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $byKey);
        }

        // Occupied along the 4-length ship
        $this->assertTrue($byKey['0:0']);
        $this->assertTrue($byKey['1:0']);
        $this->assertTrue($byKey['2:0']);
        $this->assertTrue($byKey['3:0']);

        // Not occupied on the south ray near origin
        $this->assertFalse($byKey['0:1']);
        // South ray from origin: (0,2) belongs to a 3-length horizontal ship
        $this->assertFalse($byKey['0:1']);
        $this->assertTrue($byKey['0:2']);
        $this->assertFalse($byKey['0:3']);
    }
}
