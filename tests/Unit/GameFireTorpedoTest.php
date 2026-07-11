<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use PHPUnit\Framework\TestCase;
use Tests\Support\FleetFactory;

/**
 * Torpeda startuje wyłącznie z niezatopionego NISZCZYCIELA (3-masztowca).
 * W klasycznym układzie floty niszczyciele stoją na (0,2)-(2,2) [H]
 * i (6,0)-(6,2) [V].
 */
final class GameFireTorpedoTest extends TestCase
{
    public function testFireTorpedoAcrossEntireRow(): void
    {
        $rules = new FunRuleset();
        $game = Game::create($rules);
        $fleet = FleetFactory::classic10x10();
        $game->placeFleet($fleet);

        // Torpeda z niszczyciela (0,2) na wschód przez cały wiersz
        $results = $game->fireTorpedo(new Coordinate(0, 2), Direction::E);

        $this->assertCount(10, $results);
        foreach ($results as $i => $r) {
            $this->assertSame($i, $r['x']);
            $this->assertSame(2, $r['y']);
            $this->assertContains($r['result'], ['hit', 'sunk', 'miss', 'duplicate']);
        }

        // Ponowne odpalenie w ten sam wiersz — wszystkie duplicate.
        // Start z (6,2): pierwsza torpeda (fallback bez floty przeciwnika)
        // zatopiła własnego niszczyciela na (0,2) i trafiła (6,2), ale pionowy
        // niszczyciel (6,0)-(6,2) wciąż pływa — wyrzutnia legalna.
        $resultsDup = $game->fireTorpedo(new Coordinate(6, 2), Direction::E);
        foreach ($resultsDup as $r) {
            $this->assertSame('duplicate', $r['result']);
        }
    }

    public function testFireTorpedoSouthAlongColumn(): void
    {
        $rules = new FunRuleset();
        $game = Game::create($rules);
        $fleet = FleetFactory::classic10x10();
        $game->placeFleet($fleet);

        // Z niszczyciela (6,0) w dół — 10 pól (y: 0..9)
        $results = $game->fireTorpedo(new Coordinate(6, 0), Direction::S);

        $this->assertCount(10, $results);
        foreach ($results as $i => $r) {
            $this->assertSame(6, $r['x']);
            $this->assertSame($i, $r['y']);
            $this->assertContains($r['result'], ['hit', 'sunk', 'miss', 'duplicate']);
        }
    }

    public function testTorpedoCannotBeLaunchedFromWater(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Torpedo must be launched from an unsunk destroyer');
        // (1,1) to woda
        $game->fireTorpedo(new Coordinate(1, 1), Direction::E);
    }

    public function testTorpedoCannotBeLaunchedFromNonDestroyer(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Torpedo must be launched from an unsunk destroyer');
        // (0,0) to 4-masztowiec (lotniskowiec) — nośnik nalotów, nie torped
        $game->fireTorpedo(new Coordinate(0, 0), Direction::E);
    }

    public function testTorpedoCannotBeLaunchedFromSunkDestroyer(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        // przeciwnik zatapia pionowego niszczyciela gracza (6,0)-(6,2)
        $game->fireOpponentShot(new Coordinate(6, 0));
        $game->fireOpponentShot(new Coordinate(6, 1));
        $game->fireOpponentShot(new Coordinate(6, 2));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Torpedo must be launched from an unsunk destroyer');
        $game->fireTorpedo(new Coordinate(6, 0), Direction::E);
    }

    public function testTorpedoCanBeLaunchedFromDamagedButUnsunkShip(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        // przeciwnik trafia niszczyciela gracza na (0,2) — uszkodzony, ale pływa
        $game->fireOpponentShot(new Coordinate(0, 2));

        $results = $game->fireTorpedo(new Coordinate(0, 2), Direction::E);
        $this->assertCount(10, $results);
    }

    public function testDiagonalTorpedoRunsAlongDiagonal(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        // z niszczyciela (0,2) po przekątnej SE — komórki (i, i+2)
        $results = $game->fireTorpedo(new Coordinate(0, 2), Direction::SE);

        $this->assertCount(8, $results);
        foreach ($results as $i => $r) {
            $this->assertSame($i, $r['x']);
            $this->assertSame($i + 2, $r['y']);
        }
        $this->assertSame(1, $game->weaponsState()['torpedoDiagonal']['used']);
    }

    public function testSecondDiagonalTorpedoIsRejected(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());
        $game->fireTorpedo(new Coordinate(0, 2), Direction::SE);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Diagonal torpedo limit reached');
        $game->fireTorpedo(new Coordinate(6, 0), Direction::NE);
    }

    public function testStraightTorpedoStillAvailableAfterDiagonal(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());
        $game->fireTorpedo(new Coordinate(0, 2), Direction::SE);

        // druga torpeda (prosta) przechodzi — limit ogólny to 2
        $results = $game->fireTorpedo(new Coordinate(6, 0), Direction::E);
        $this->assertNotEmpty($results);
        $this->assertSame(2, $game->weaponsState()['torpedo']['used']);
    }

    public function testFailedLaunchDoesNotConsumeTorpedo(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        try {
            $game->fireTorpedo(new Coordinate(1, 1), Direction::E); // woda
        } catch (\DomainException) {
            // oczekiwane
        }

        $this->assertSame(0, $game->weaponsState()['torpedo']['used']);
    }
}
