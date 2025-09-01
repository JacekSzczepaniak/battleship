<?php
declare(strict_types=1);

use App\Kernel;
use App\Domain\Game\{Game, ClassicRuleset, BoardSize, Ship, Orientation, Coordinate};
use App\Application\Ports\GameRepository;
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

    public function test_persist_and_load_game_with_fleet(): void
    {
        /** @var GameRepository $repo */
        $repo = static::getContainer()->get(GameRepository::class);

        $g = Game::create(new ClassicRuleset(new BoardSize(12,10)));
        $ships = [
            new Ship(new Coordinate(0,0), Orientation::H, 4),
            new Ship(new Coordinate(0,2), Orientation::H, 3),
            new Ship(new Coordinate(5,2), Orientation::V, 3),
            new Ship(new Coordinate(8,0), Orientation::V, 2),
            new Ship(new Coordinate(10,5), Orientation::H, 2),
            new Ship(new Coordinate(2,7), Orientation::H, 2),
            new Ship(new Coordinate(0,5), Orientation::H, 1),
            new Ship(new Coordinate(5,7), Orientation::H, 1),
            new Ship(new Coordinate(7,7), Orientation::H, 1),
            new Ship(new Coordinate(11,9), Orientation::H, 1),
        ];
        $g->placeFleet($ships);

        $repo->save($g);

        $loaded = $repo->get($g->id());
        self::assertNotNull($loaded);
        self::assertSame((string)$g->id(), (string)$loaded->id());
        self::assertNotNull($loaded->fleet());
    }
}
