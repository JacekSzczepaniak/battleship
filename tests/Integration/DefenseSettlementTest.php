<?php

declare(strict_types=1);

use App\Application\Expedition\CreateProfile;
use App\Application\Expedition\SettleBattle;
use App\Application\Expedition\StartIslandBattle;
use App\Application\Expedition\WorldFactory;
use App\Application\Game\PlaceFleet;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Rozliczenie obrony przed kontratakiem (#78) na repozytoriach Doctrine: bitwa
 * rozstrzygnięta strzałami w znane pola (generator testowy stawia lustrzany
 * kuter wroga na (0,0) poziomo), stan profilu czytany ponownie z bazy.
 *
 * @group integration
 */
final class DefenseSettlementTest extends KernelTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    protected function setUp(): void
    {
        self::bootKernel();
    }

    public function testWonDefenseKeepsTheFlagAndPaysTheIslandRewards(): void
    {
        [$profileId, $gameId] = $this->defenseBattle();

        $game = $this->games()->get(new GameId($gameId));
        $game->fireShot(new Coordinate(0, 0));
        $game->fireShot(new Coordinate(1, 0));
        $this->games()->save($game);

        $settled = $this->settle()->handle($profileId, $gameId);
        $profile = $this->profiles()->get(new ProfileId($profileId));

        self::assertSame('won', $settled['result']);
        self::assertSame(['held' => true, 'stockpileLost' => ['wood' => 0, 'steel' => 0, 'gold' => 0]], $settled['defense']);
        self::assertSame(80 + 70, $profile->xp());
        self::assertTrue($profile->ownsIsland('wyspa-sygnalowa'));
        self::assertNull($profile->counterattack());
        self::assertTrue($profile->lastCounterattack()['held'] ?? null);
        self::assertEquals(new Resources(20 + 20, 10 + 10, 4 + 5), $profile->stockpile('wyspa-sygnalowa'));
    }

    public function testLostDefenseLosesTheIslandHalfTheStockpileAndTheSunkShip(): void
    {
        [$profileId, $gameId] = $this->defenseBattle();

        $game = $this->games()->get(new GameId($gameId));
        foreach ([[5, 5], [6, 5]] as [$x, $y]) {
            if (!in_array(['x' => $x, 'y' => $y], $game->opponentShots(), true)) {
                $game->fireOpponentShot(new Coordinate($x, $y));
            }
        }
        $this->games()->save($game);

        $settled = $this->settle()->handle($profileId, $gameId);
        $profile = $this->profiles()->get(new ProfileId($profileId));

        self::assertSame('lost', $settled['result']);
        self::assertSame(['held' => false, 'stockpileLost' => ['wood' => 10, 'steel' => 5, 'gold' => 2]], $settled['defense']);
        self::assertSame(['kuter'], $settled['lostShips']);
        self::assertSame(80 + 15, $profile->xp());
        self::assertFalse($profile->ownsIsland('wyspa-sygnalowa'));
        self::assertNull($profile->counterattack());
        // połowa magazynu przepadła, łup za porażkę (8 drewna) doszedł po niej
        self::assertEquals(new Resources(10 + 8, 5, 2), $profile->stockpile('wyspa-sygnalowa'));
        self::assertCount(3, $profile->fleet());
    }

    /**
     * Kapitan pod banderą na Wyspie Sygnałowej, kontratak w toku, obrona
     * przyjęta samym kutrem rozstawionym na (5,5) poziomo.
     *
     * @return array{0:string, 1:string} [id profilu, id gry]
     */
    private function defenseBattle(): array
    {
        $created = static::getContainer()->get(CreateProfile::class)->handle('Jacek');
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($created);
        $this->profiles()->save(CaptainProfile::fromSnapshot(
            $created->id(), $created->name(), 80, [], $created->fleet(), Resources::none(),
            $created->worldSeed(), $world->islandPosition('wyspa-sygnalowa'), null, 0, 25, null,
            ['wyspa-sygnalowa' => new Resources(20, 10, 4)], null, [], ['wyspa-sygnalowa' => 0],
            ['pending' => ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null], 'calmFrom' => 0, 'rolledTo' => 24, 'last' => null],
        ));
        $profileId = (string) $created->id();

        $game = static::getContainer()->get(StartIslandBattle::class)->handle($profileId, 'wyspa-sygnalowa', [$created->fleet()[0]->id]);
        static::getContainer()->get(PlaceFleet::class)->handle((string) $game->id(), [['x' => 5, 'y' => 5, 'o' => 'H', 'l' => 2]]);

        return [$profileId, (string) $game->id()];
    }

    private function profiles(): ProfileRepository
    {
        return static::getContainer()->get(ProfileRepository::class);
    }

    private function games(): GameRepository
    {
        return static::getContainer()->get(GameRepository::class);
    }

    private function settle(): SettleBattle
    {
        return static::getContainer()->get(SettleBattle::class);
    }
}
