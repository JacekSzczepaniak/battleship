<?php

declare(strict_types=1);

namespace Tests\Integration;

use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Game;
use App\Domain\Game\GameRepository;
use App\Domain\Game\Orientation;
use App\Domain\Shared\GameId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Tests\Support\FleetFactory;

final class DoctrineGameRepositoryShotsTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testPersistAndLoadWithShots(): void
    {
        self::bootKernel();
        $container = static::getContainer();
        /** @var GameRepository $repo */
        $repo = $container->get(GameRepository::class);

        // given: gra z rozstawioną flotą zgodną z ClassicRuleset
        $rules = new ClassicRuleset();
        $game = Game::create($rules);
        $gameId = (string) $game->id();

        $fleet = FleetFactory::classic10x10();
        $game->placeFleet($fleet);

        // strzały: trafienie i pudło
        $game->fireShot(new Coordinate(0, 0)); // hit (część 4-masztowca)
        $game->fireShot(new Coordinate(5, 5)); // miss

        // when: zapis i odczyt
        $repo->save($game);
        $loaded = $repo->get(new GameId($gameId));

        self::assertNotNull($loaded);

        // then: ponowny strzał w (0,0) -> duplicate
        $dup = $loaded->fireShot(new Coordinate(0, 0));
        self::assertSame('duplicate', $dup->value);

        // oraz wciąż można strzelać w inne miejsca
        $res = $loaded->fireShot(new Coordinate(1, 0)); // kolejna komórka 4-masztowca
        self::assertContains($res->value, ['hit', 'sunk']);
    }
}
