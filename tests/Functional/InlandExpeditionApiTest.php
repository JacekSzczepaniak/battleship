<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\WildIsland;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Ekspedycje w głąb dzikich wysp i artefakty jako questowa ścieżka drzewka (#144) przez API. */
final class InlandExpeditionApiTest extends WebTestCase
{
    private const SEED = 4444;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testStagesAreAnnouncedUpFrontAndReturnKeepsTheRewards(): void
    {
        $client = static::createClient();
        $tick = $this->tickWhere(Sea::START, static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'passed' === $p->startInlandExpedition($i, $m)['result']
            && 'passed' === $p->goDeeperInland($i, $m)['result']);
        $profileId = $this->captain($client, Sea::START, $tick);

        $wild = $this->expedition($client, $profileId)['world']['wildIsland'];
        self::assertSame('wyspa-papug', $wild['id']);
        self::assertSame('Wyspa Papug', $wild['name']);
        self::assertTrue($wild['present']);
        self::assertTrue($wild['unlocked']);
        self::assertSame([20, 35, 50], array_column($wild['stages'], 'risk'));
        self::assertSame(['wood' => 8, 'steel' => 0, 'gold' => 0], $wild['stages'][0]['reward']);
        self::assertNotSame('', $wild['stages'][2]['story']);
        self::assertSame([['type' => 'artifact', 'chance' => 20, 'owned' => false]], $wild['treasures']);
        self::assertNull($wild['blocker']);
        self::assertNull($wild['expedition']);

        $one = $this->explore($client, $profileId, 'start');
        self::assertResponseIsSuccessful();
        self::assertSame(1, $one['stage']);
        self::assertSame('passed', $one['result']);
        self::assertSame($tick + 1, $one['tick']);
        $two = $this->explore($client, $profileId, 'deeper');
        self::assertSame(['wood' => 14, 'steel' => 6, 'gold' => 0], $two['collected']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $two['cargo']);
        self::assertSame(['island' => 'wyspa-papug', 'stage' => 2, 'collected' => ['wood' => 14, 'steel' => 6, 'gold' => 0], 'startedAt' => $tick], $two['expedition']);

        // w trakcie ekspedycji flota nie odpływa
        $sail = $this->post($client, "/api/profiles/$profileId/sail", ['x' => 0, 'y' => 0]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('INLAND_IN_PROGRESS', $sail['error']['code']);
        self::assertSame(2, $this->expedition($client, $profileId)['world']['wildIsland']['expedition']['stage']);

        $back = $this->explore($client, $profileId, 'return');
        self::assertResponseIsSuccessful();
        self::assertSame('returned', $back['result']);
        self::assertSame(['wood' => 14, 'steel' => 6, 'gold' => 0], $back['received']);
        self::assertSame(['wood' => 14, 'steel' => 6, 'gold' => 0], $back['cargo']);
        self::assertSame($tick + 2, $back['tick']);
        self::assertNull($back['expedition']);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame('resting', $expedition['world']['wildIsland']['blocker']);
        self::assertSame($tick + 2 + 15, $expedition['world']['wildIsland']['readyAt']);
        self::assertSame('inland-returned', $expedition['log'][0]['event']);
        self::assertSame('Wyspa Papug', $expedition['log'][0]['islandName']);

        $again = $this->explore($client, $profileId, 'start');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('WILD_ISLAND_RESTING', $again['error']['code']);
    }

    public function testFailureTakesTheRewardsAndDamagesAShip(): void
    {
        $client = static::createClient();
        $tick = $this->tickWhere(Sea::START, static fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'passed' === $p->startInlandExpedition($i, $m)['result']
            && 'failed' === $p->goDeeperInland($i, $m)['result']);
        $profileId = $this->captain($client, Sea::START, $tick);

        $this->explore($client, $profileId, 'start');
        $failed = $this->explore($client, $profileId, 'deeper');
        self::assertResponseIsSuccessful();
        self::assertSame('failed', $failed['result']);
        self::assertSame(['wood' => 8, 'steel' => 0, 'gold' => 0], $failed['lost']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $failed['cargo']);

        $expedition = $this->expedition($client, $profileId);
        $damaged = array_values(array_filter($expedition['fleet'], static fn (array $s) => $s['damaged']));
        self::assertCount(1, $damaged);
        self::assertSame($failed['damagedShip'], $damaged[0]['type']);
        self::assertSame('inland-failed', $expedition['log'][0]['event']);
        self::assertNull($expedition['world']['wildIsland']['expedition']);
    }

    public function testArtifactUnlocksATechnologyWithoutAnAcademy(): void
    {
        $client = static::createClient();
        $tick = $this->tickWhere(Sea::START, fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'artifact' === ($this->allTheWay($p, $i, $m)['treasure']['type'] ?? null));
        $profileId = $this->captain($client, Sea::START, $tick);

        $outcome = $this->explore($client, $profileId, 'start');
        while ('passed' === $outcome['result']) {
            $outcome = $this->explore($client, $profileId, 'deeper');
        }
        self::assertSame('completed', $outcome['result']);
        self::assertSame('artifact', $outcome['treasure']['type']);
        $technology = $outcome['treasure']['technology'];

        $research = $this->expedition($client, $profileId)['research'];
        self::assertSame([$technology], $research['artifacts']);
        $node = array_column($research['technologies'], null, 'id')[$technology];
        self::assertSame('researched', $node['state']);
        self::assertSame('academy', $node['unlock']);
    }

    public function testBlackPearlLegendRaisesVisibility(): void
    {
        $client = static::createClient();
        $pearl = StaticSeaGraph::BLACK_PEARL;
        $tick = $this->tickWhere($pearl, fn (CaptainProfile $p, WildIsland $i, WorldMap $m) => 'legend' === ($this->allTheWay($p, $i, $m)['treasure']['type'] ?? null));
        $profileId = $this->captain($client, $pearl, $tick);

        $before = $this->expedition($client, $profileId);
        self::assertSame(1, $before['world']['visibility']);
        self::assertSame([['type' => 'legend', 'chance' => 30, 'owned' => false], ['type' => 'artifact', 'chance' => 30, 'owned' => false]], $before['world']['wildIsland']['treasures']);
        self::assertSame('quest-only', array_column($before['research']['technologies'], null, 'id')['czarna-perla']['blocker']);

        $outcome = $this->explore($client, $profileId, 'start', 'wyspa-czaszki');
        while ('passed' === $outcome['result']) {
            $outcome = $this->explore($client, $profileId, 'deeper', 'wyspa-czaszki');
        }
        self::assertSame(['type' => 'legend', 'technology' => 'czarna-perla'], $outcome['treasure']);

        $after = $this->expedition($client, $profileId);
        self::assertSame(2, $after['world']['visibility']);
        self::assertSame('researched', array_column($after['research']['technologies'], null, 'id')['czarna-perla']['state']);
        self::assertSame(['czarna-perla'], $after['research']['artifacts']);
        self::assertTrue($after['world']['wildIsland']['treasures'][0]['owned']);
    }

    public function testRefusals(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, Sea::START, 0);

        $this->explore($client, $profileId, 'dig');
        self::assertResponseStatusCodeSame(400);
        $unknown = $this->post($client, "/api/profiles/$profileId/wild-islands/wyspa-czaszki/expedition", ['action' => 'start']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('WILD_ISLAND_NOT_FOUND', $unknown['error']['code']);
        $back = $this->explore($client, $profileId, 'return');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_INLAND_EXPEDITION', $back['error']['code']);

        $rookie = $this->captain($client, Sea::START, 0, xp: 0);
        $locked = $this->explore($client, $rookie, 'start');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('WILD_ISLAND_LOCKED', $locked['error']['code']);
        self::assertSame('rank', $this->expedition($client, $rookie)['world']['wildIsland']['blocker']);

        $away = $this->captain($client, Sea::START, 0, atWildIsland: false);
        $absent = $this->explore($client, $away, 'start');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_WILD_ISLAND', $absent['error']['code']);
    }

    /** @param callable(CaptainProfile, WildIsland, WorldMap): bool $run */
    private function tickWhere(string $sea, callable $run): int
    {
        $graph = static::getContainer()->get(SeaGraph::class);
        $island = $graph->sea($sea)->wildIsland;
        for ($tick = 0; $tick < 3000; ++$tick) {
            $map = $this->map($sea);
            $profile = $this->domainCaptain(CaptainProfile::create('Jacek'), $sea, $tick, 1400, $map->wildIslandPosition());
            $profile->ensureWorldState($map);
            if ($run($profile, $island, $map)) {
                return $tick;
            }
        }

        self::fail('No tick with this outcome');
    }

    /** @return array<string, mixed> */
    private function allTheWay(CaptainProfile $profile, WildIsland $island, WorldMap $map): array
    {
        $outcome = $profile->startInlandExpedition($island, $map);
        while ('passed' === $outcome['result']) {
            $outcome = $profile->goDeeperInland($island, $map);
        }

        return $outcome;
    }

    private function map(string $sea): WorldMap
    {
        $graph = static::getContainer()->get(SeaGraph::class);

        return $graph->sea($sea)->map(self::SEED, $graph->passagesFrom($sea));
    }

    /** @param array{x:int,y:int} $position */
    private function domainCaptain(CaptainProfile $profile, string $sea, int $tick, int $xp, array $position): CaptainProfile
    {
        return CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), $xp, [],
            [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Niszczyciel)],
            Resources::none(), self::SEED, $position, ["{$position['x']}:{$position['y']}"],
            tick: $tick,
            sea: $sea,
        );
    }

    /** Kapitan ze stałym seedem świata na dzikiej wyspie akwenu (albo na sektorze startu). */
    private function captain(KernelBrowser $client, string $sea, int $tick, int $xp = 1400, bool $atWildIsland = true): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $map = $this->map($sea);
        $position = $atWildIsland ? $map->wildIslandPosition() : $map->startPosition();
        $repository->save($this->domainCaptain($profile, $sea, $tick, $xp, $position));

        return $profileId;
    }

    /** @return array<string,mixed> */
    private function explore(KernelBrowser $client, string $profileId, string $action, string $island = 'wyspa-papug'): array
    {
        return $this->post($client, "/api/profiles/$profileId/wild-islands/$island/expedition", ['action' => $action]);
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
