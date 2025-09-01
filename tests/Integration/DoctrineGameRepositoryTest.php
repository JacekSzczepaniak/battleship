<?php

declare(strict_types=1);

use App\Domain\Game\BoardSize;
use App\Domain\Game\ClassicRuleset;
use App\Domain\Game\Coordinate;
use App\Domain\Game\Game;
use App\Domain\Game\GameRepository;
use App\Domain\Game\Orientation;
use App\Domain\Game\Ship;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group integration
 */
final class DoctrineGameRepositoryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        // wyczyść tabelę przed testem
        $conn = static::getContainer()->get('doctrine')->getConnection();
        $conn->executeStatement('TRUNCATE TABLE games');
    }

    public function testPersistAndLoadGameWithFleet(): void
    {
        /** @var GameRepository $repo */
        $repo = static::getContainer()->get(GameRepository::class);

        $g = Game::create(new ClassicRuleset(new BoardSize(12, 10)));
        $ships = [
            new Ship(new Coordinate(0, 0), Orientation::H, 4),
            new Ship(new Coordinate(0, 2), Orientation::H, 3),
            new Ship(new Coordinate(5, 2), Orientation::V, 3),
            new Ship(new Coordinate(8, 0), Orientation::V, 2),
            new Ship(new Coordinate(10, 5), Orientation::H, 2),
            new Ship(new Coordinate(2, 7), Orientation::H, 2),
            new Ship(new Coordinate(0, 5), Orientation::H, 1),
            new Ship(new Coordinate(5, 7), Orientation::H, 1),
            new Ship(new Coordinate(7, 7), Orientation::H, 1),
            new Ship(new Coordinate(11, 9), Orientation::H, 1),
        ];
        $g->placeFleet($ships);

        $repo->save($g);

        $loaded = $repo->get($g->id());
        self::assertNotNull($loaded);
        self::assertSame((string) $g->id(), (string) $loaded->id());
        self::assertNotNull($loaded->fleet());
    }
}
