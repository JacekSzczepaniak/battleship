<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\SeaState;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Kontrataki i pełna bandera na wielu akwenach (#109) przez API. */
final class MultiSeaCounterattackApiTest extends WebTestCase
{
    private const OCEAN = StaticSeaGraph::STORM_OCEAN;
    private const STRAIT = StaticSeaGraph::GUARDED_STRAIT;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFullFlagOfShipwreckSeaDoesNotHelpTheDefenseOfAnOceanIsland(): void
    {
        $client = static::createClient();
        $owned = [...$this->islandsOf(Sea::START), 'rafa-kotwiczna'];
        [$profileId, $fleet] = $this->captain(
            $client, sea: self::OCEAN, at: 'rafa-kotwiczna', owned: $owned, tick: 25,
            pending: ['island' => 'rafa-kotwiczna', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null],
        );

        $expedition = $this->expedition($client, $profileId);
        $pending = $expedition['counterattack']['pending'];
        self::assertSame(['id' => self::OCEAN, 'name' => 'Ocean Sztormów'], $pending['sea']);
        self::assertFalse($pending['fleetAway']);
        self::assertSame(['ticks' => 0, 'gates' => 0, 'inTime' => true], $pending['route']);
        self::assertFalse($pending['fullFlag']);
        self::assertSame([
            ['sea' => Sea::START, 'name' => 'Morze Rozbitków', 'owned' => 6, 'total' => 6, 'fullFlag' => true],
            ['sea' => self::OCEAN, 'name' => 'Ocean Sztormów', 'owned' => 1, 'total' => 5, 'fullFlag' => false],
        ], $expedition['world']['flags']);

        // bez bonusu: napastnik w lustrzanym składzie i z pierwszą salwą
        $gameId = $this->post($client, "/api/profiles/$profileId/islands/rafa-kotwiczna/battle", ['shipIds' => [$fleet[0]]])['id'];
        self::assertResponseStatusCodeSame(201);
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2]]]);
        self::assertResponseIsSuccessful();
        $client->request('GET', "/api/games/$gameId");
        $game = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame($game['allowedShips'], $game['opponentShips']);
        self::assertCount(1, [...$game['playerUnderFireGrid']['hits'], ...$game['playerUnderFireGrid']['misses']]);
    }

    public function testFullFlagOfTheTargetSeaCountsWhileTheFleetIsElsewhere(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captain(
            $client, sea: self::OCEAN, at: 'rafa-kotwiczna', owned: [...$this->islandsOf(Sea::START), 'rafa-kotwiczna'], tick: 25,
            pending: ['island' => 'mielizny', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null],
        );

        $pending = $this->expedition($client, $profileId)['counterattack']['pending'];
        self::assertSame(Sea::START, $pending['sea']['id']);
        self::assertTrue($pending['fleetAway']);
        self::assertTrue($pending['fullFlag']);
    }

    public function testWarningComparesTicksLeftWithTheRouteThroughTheGate(): void
    {
        $client = static::createClient();
        [$profileId, , $profile] = $this->captain(
            $client, sea: Sea::START, at: 'zatoka-rozbitka', owned: ['rafa-kotwiczna'], tick: 25,
            pending: ['island' => 'rafa-kotwiczna', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null],
        );

        $pending = $this->expedition($client, $profileId)['counterattack']['pending'];
        self::assertSame('Rafa Kotwiczna', $pending['islandName']);
        self::assertSame(['id' => self::OCEAN, 'name' => 'Ocean Sztormów'], $pending['sea']);
        self::assertTrue($pending['fleetAway']);
        self::assertSame(7, $pending['ticksLeft']);
        $ticks = $this->legs($this->map($profile, Sea::START), 'zatoka-rozbitka')
            + 1 + $this->legs($this->map($profile, self::OCEAN), 'rafa-kotwiczna');
        self::assertSame(['ticks' => $ticks, 'gates' => 1, 'inTime' => $ticks < 7], $pending['route']);

        // brama ze strażnikiem zamknięta: drogi nie ma
        [$closed] = $this->captain(
            $client, sea: Sea::START, at: 'zatoka-rozbitka', owned: ['rafa-kotwiczna'], tick: 25, opened: false,
            pending: ['island' => 'rafa-kotwiczna', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null],
        );
        self::assertNull($this->expedition($client, $closed)['counterattack']['pending']['route']);
    }

    public function testStrikeOnAnotherSeaTakesTheIslandUnlessAFortGuardsIt(): void
    {
        $client = static::createClient();
        $pending = ['island' => 'rafa-kotwiczna', 'announcedAt' => 10, 'strikeAt' => 22, 'defense' => null];

        [$lost] = $this->captain($client, sea: Sea::START, at: 'zatoka-rozbitka', owned: ['rafa-kotwiczna'], tick: 25, pending: $pending);
        $expedition = $this->expedition($client, $lost);
        self::assertNull($expedition['counterattack']['pending']);
        self::assertSame('rafa-kotwiczna', $expedition['counterattack']['last']['island']);
        self::assertTrue($expedition['counterattack']['last']['absent']);
        self::assertFalse($expedition['counterattack']['last']['plundered']);
        self::assertSame(0, $expedition['profile']['ownedIslands']);
        self::assertSame(['event' => 'island-lost', 'islandName' => 'Rafa Kotwiczna'], array_intersect_key($expedition['log'][0], ['event' => 1, 'islandName' => 1]));

        [$guarded] = $this->captain(
            $client, sea: Sea::START, at: 'zatoka-rozbitka', owned: ['rafa-kotwiczna'], tick: 25, pending: $pending,
            buildings: ['rafa-kotwiczna' => [BuildingType::Fort]],
        );
        $expedition = $this->expedition($client, $guarded);
        self::assertTrue($expedition['counterattack']['last']['plundered']);
        self::assertSame(1, $expedition['profile']['ownedIslands']);
        self::assertSame('island-plundered', $expedition['log'][0]['event']);
    }

    /** @return list<string> */
    private function islandsOf(string $seaId): array
    {
        return array_map(static fn ($i) => $i->id, static::getContainer()->get(SeaGraph::class)->sea($seaId)->islands->all());
    }

    private function map(CaptainProfile $profile, string $seaId): WorldMap
    {
        return static::getContainer()->get(WorldFactory::class)->mapOf(static::getContainer()->get(SeaGraph::class)->sea($seaId), $profile);
    }

    /** Rejsy (zasięg 1) z wyspy do bramy cieśniny na jej akwenie. */
    private function legs(WorldMap $map, string $islandId): int
    {
        $island = $map->islandPosition($islandId);
        $gate = $map->gatePosition(self::STRAIT);

        return max(abs($island['x'] - $gate['x']), abs($island['y'] - $gate['y']));
    }

    /**
     * Porucznik z flotą na wyspie danego akwenu, znający oba akweny (stan
     * drugiego akwenu: sektor bramy), z cieśniną otwartą i zadanym kontratakiem.
     *
     * @param list<string>                                                              $owned
     * @param array{island:string, announcedAt:int, strikeAt:int, defense:?string}|null $pending
     * @param array<string, list<BuildingType>>                                         $buildings
     *
     * @return array{0: string, 1: list<string>, 2: CaptainProfile} [id profilu, id statków, profil]
     */
    private function captain(
        KernelBrowser $client,
        string $sea,
        string $at,
        array $owned,
        int $tick,
        ?array $pending,
        array $buildings = [],
        bool $opened = true,
    ): array {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $here = $this->map($profile, $sea)->islandPosition($at);
        $otherSea = Sea::START === $sea ? self::OCEAN : Sea::START;
        $otherGate = $this->map($profile, $otherSea)->gatePosition(self::STRAIT);

        $saved = CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 400, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $here, ["{$here['x']}:{$here['y']}"],
            tick: $tick,
            depositTicks: array_fill_keys($owned, $tick),
            ownedIslands: array_fill_keys($owned, 0),
            counterattacks: ['pending' => $pending, 'calmFrom' => 0, 'rolledTo' => $tick - 1, 'last' => null],
            buildings: $buildings,
            sea: $sea,
            seaStates: [$otherSea => new SeaState($otherGate, ["{$otherGate['x']}:{$otherGate['y']}"], ["{$otherGate['x']}:{$otherGate['y']}"])],
            openedGates: $opened ? [self::STRAIT => 0] : [],
        );
        $repository->save($saved);

        return [$profileId, array_map(static fn ($s) => $s->id, $profile->fleet()), $saved];
    }

    /** @return array<string,mixed> */
    private function expedition(KernelBrowser $client, string $profileId): array
    {
        $client->request('GET', "/api/profiles/$profileId/expedition");
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function post(KernelBrowser $client, string $url, array $payload = []): array
    {
        $client->request(
            'POST',
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return json_decode($client->getResponse()->getContent() ?: '{}', true) ?? [];
    }
}
