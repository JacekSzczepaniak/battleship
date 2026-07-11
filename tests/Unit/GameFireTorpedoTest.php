<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Domain\Game\Coordinate;
use App\Domain\Game\Direction;
use App\Domain\Game\FunRuleset;
use App\Domain\Game\Game;
use PHPUnit\Framework\TestCase;
use Tests\Support\FleetFactory;

final class GameFireTorpedoTest extends TestCase
{
    public function testFireTorpedoAcrossEntireRow(): void
    {
        $rules = new FunRuleset();
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

        // Ponowne odpalenie w ten sam wiersz — wszystkie duplicate.
        // Start z (6,0): w tym teście (fallback bez floty przeciwnika) pierwsza
        // torpeda zatopiła własnego 4-masztowca na (0,0), a wyrzutnią może być
        // tylko niezatopiony statek; trójmasztowiec (6,0)-(6,2) wciąż pływa.
        $resultsDup = $game->fireTorpedo(new Coordinate(6, 0), Direction::E);
        foreach ($resultsDup as $r) {
            $this->assertSame('duplicate', $r['result']);
        }
    }

    public function testFireTorpedoNorthFromCenter(): void
    {
        $rules = new FunRuleset();
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

    public function testTorpedoCannotBeLaunchedFromWater(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Torpedo must be launched from an unsunk ship');
        // (1,1) to woda — 4-masztowiec stoi w wierszu 0, trójmasztowiec w wierszu 2
        $game->fireTorpedo(new Coordinate(1, 1), Direction::E);
    }

    public function testTorpedoCannotBeLaunchedFromSunkShip(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        // przeciwnik zatapia jedynkę gracza na (0,6)
        $game->fireOpponentShot(new Coordinate(0, 6));

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Torpedo must be launched from an unsunk ship');
        $game->fireTorpedo(new Coordinate(0, 6), Direction::E);
    }

    public function testTorpedoCanBeLaunchedFromDamagedButUnsunkShip(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        // przeciwnik trafia 4-masztowiec gracza na (0,0) — uszkodzony, ale pływa
        $game->fireOpponentShot(new Coordinate(0, 0));

        $results = $game->fireTorpedo(new Coordinate(0, 0), Direction::E);
        $this->assertCount(10, $results);
    }

    public function testDiagonalTorpedoRunsAlongDiagonal(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());

        // z 4-masztowca (0,0) po przekątnej SE — komórki (i,i)
        $results = $game->fireTorpedo(new Coordinate(0, 0), Direction::SE);

        $this->assertCount(10, $results);
        foreach ($results as $i => $r) {
            $this->assertSame($i, $r['x']);
            $this->assertSame($i, $r['y']);
        }
        $this->assertSame(1, $game->weaponsState()['torpedoDiagonal']['used']);
    }

    public function testSecondDiagonalTorpedoIsRejected(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());
        $game->fireTorpedo(new Coordinate(0, 0), Direction::SE);

        $this->expectException(\DomainException::class);
        $this->expectExceptionMessage('Diagonal torpedo limit reached');
        $game->fireTorpedo(new Coordinate(0, 2), Direction::NE);
    }

    public function testStraightTorpedoStillAvailableAfterDiagonal(): void
    {
        $game = Game::create(new FunRuleset());
        $game->placeFleet(FleetFactory::classic10x10());
        $game->fireTorpedo(new Coordinate(0, 0), Direction::SE);

        // druga torpeda (prosta) przechodzi — limit ogólny to 2
        $results = $game->fireTorpedo(new Coordinate(0, 2), Direction::E);
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
