<?php

declare(strict_types=1);

use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * @group integration
 */
final class DoctrineProfileRepositoryTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();

        $conn = static::getContainer()->get('doctrine')->getConnection();
        $conn->executeStatement('TRUNCATE TABLE profiles');
    }

    public function testPersistAndLoadSettlementBuildings(): void
    {
        /** @var ProfileRepository $repo */
        $repo = static::getContainer()->get(ProfileRepository::class);

        $created = CaptainProfile::create('Jacek');
        $profile = CaptainProfile::fromSnapshot(
            $created->id(), 'Jacek', 40, [], null, Resources::none(),
            ownedIslands: ['mielizny' => 3],
            buildings: ['mielizny' => [BuildingType::Tartak], 'archipelag-mgiel' => [BuildingType::Kopalnia]],
        );
        $repo->save($profile);

        // świeży EntityManager: odczyt idzie z bazy, nie z mapy tożsamości
        static::getContainer()->get('doctrine')->getManager()->clear();
        $loaded = $repo->get($profile->id());

        self::assertNotNull($loaded);
        self::assertSame(
            ['mielizny' => [BuildingType::Tartak], 'archipelag-mgiel' => [BuildingType::Kopalnia]],
            $loaded->buildings(),
        );
        self::assertTrue($loaded->hasActiveBuilding('mielizny', BuildingType::Tartak));
        // kopalnia stoi, ale Archipelag nie jest pod banderą
        self::assertFalse($loaded->hasActiveBuilding('archipelag-mgiel', BuildingType::Kopalnia));
    }
}
