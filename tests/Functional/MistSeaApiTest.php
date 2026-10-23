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
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Nawigacja oceaniczna, otwarty ocean i Morze Mgieł (#108) przez API. */
final class MistSeaApiTest extends WebTestCase
{
    private const OCEAN = StaticSeaGraph::STORM_OCEAN;
    private const MIST = StaticSeaGraph::MIST_SEA;
    private const GATE = StaticSeaGraph::OPEN_OCEAN;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testOceanNavigationNeedsTwoSectorSailingAndCaptainRank(): void
    {
        $client = static::createClient();

        $noPrerequisite = $this->scholar($client, xp: 600, researched: [Technology::MapySztormowe]);
        $refused = $this->post($client, "/api/profiles/$noPrerequisite/islands/zatoka-rozbitka/research", ['technology' => 'nawigacja-oceaniczna']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TECHNOLOGY_PREREQUISITE', $refused['error']['code']);

        $lieutenant = $this->scholar($client, xp: 599, researched: [Technology::MapySztormowe, Technology::ZeglugaDwusektorowa]);
        $node = array_column($this->expedition($client, $lieutenant)['research']['technologies'], null, 'id')['nawigacja-oceaniczna'];
        self::assertSame(['id' => 'nawigacja-oceaniczna', 'branch' => 'nawigacja', 'level' => 3, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'rank'], $node);
        $refused = $this->post($client, "/api/profiles/$lieutenant/islands/zatoka-rozbitka/research", ['technology' => 'nawigacja-oceaniczna']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('TECHNOLOGY_LOCKED', $refused['error']['code']);

        $captain = $this->scholar($client, xp: 600, researched: [Technology::MapySztormowe, Technology::ZeglugaDwusektorowa]);
        $started = $this->post($client, "/api/profiles/$captain/islands/zatoka-rozbitka/research", ['technology' => 'nawigacja-oceaniczna']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(14, $started['ticksLeft']);
        // 100/100/100 w magazynie minus 60/50/30
        self::assertSame(['wood' => 40, 'steel' => 50, 'gold' => 70], $started['stockpile']);
    }

    public function testOpenOceanIsLockedWithoutTheTechnology(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, self::OCEAN);

        $gate = array_column($this->expedition($client, $profileId)['world']['gates'], null, 'id')[self::GATE];
        self::assertSame('Otwarty ocean', $gate['name']);
        self::assertSame('east', $gate['edge']);
        self::assertSame(['id' => self::MIST, 'name' => 'Morze Mgieł', 'theme' => ['stormChance' => 10, 'visibility' => 0, 'depositMultiplier' => 2]], $gate['to']);
        self::assertTrue($gate['present']);
        self::assertSame('technology', $gate['blocker']);
        self::assertSame('nawigacja-oceaniczna', $gate['technology']);
        self::assertNull($gate['guardian']);

        $refused = $this->post($client, "/api/profiles/$profileId/gates/".self::GATE.'/pass');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('GATE_LOCKED', $refused['error']['code']);
        // otwarty ocean nie ma bitwy
        $battle = $this->post($client, "/api/profiles/$profileId/gates/".self::GATE.'/battle');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('GATE_HAS_NO_GUARDIAN', $battle['error']['code']);
    }

    public function testOpenOceanLeadsToTheSeaOfMistsAndBackWhereTheFleetSeesOnlyItsSector(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, self::OCEAN, researched: [Technology::ZeglugaDwusektorowa, Technology::NawigacjaOceaniczna], tick: 10);

        $passed = $this->post($client, "/api/profiles/$profileId/gates/".self::GATE.'/pass');
        self::assertResponseIsSuccessful();
        self::assertSame(self::MIST, $passed['sea']['id']);
        self::assertSame(self::GATE, $passed['atGate']);
        self::assertSame(11, $passed['tick']);
        $arrival = $passed['position'];
        self::assertSame(0, $arrival['x']);
        self::assertSame(["{$arrival['x']}:{$arrival['y']}"], $passed['discoveredNow']);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame(['kotwicowisko-mgiel', 'wyspa-szeptow', 'mielizna-widm', 'archipelag-slepcow', 'cytadela-mgiel'], array_column($expedition['islands'], 'id'));
        self::assertSame(["{$arrival['x']}:{$arrival['y']}"], $expedition['world']['discovered']);
        $back = $expedition['world']['gates'][0];
        self::assertSame(self::OCEAN, $back['to']['id']);
        self::assertSame('west', $back['edge']);
        self::assertNull($back['blocker']);

        // Żegluga dwusektorowa: cel i sektor po drodze, nic więcej
        $target = ['x' => 2, 'y' => $arrival['y']];
        $sailed = $this->post($client, "/api/profiles/$profileId/sail", $target);
        self::assertResponseIsSuccessful();
        self::assertSame(["1:{$arrival['y']}", "2:{$arrival['y']}"], $sailed['discoveredNow']);
        $discovered = $this->expedition($client, $profileId)['world']['discovered'];
        sort($discovered);
        $expected = ["0:{$arrival['y']}", "1:{$arrival['y']}", "2:{$arrival['y']}"];
        sort($expected);
        self::assertSame($expected, $discovered);

        $this->post($client, "/api/profiles/$profileId/sail", $arrival);
        $home = $this->post($client, "/api/profiles/$profileId/gates/".self::GATE.'/pass');
        self::assertResponseIsSuccessful();
        self::assertSame(self::OCEAN, $home['sea']['id']);
        self::assertSame(14, $home['tick']);
    }

    /**
     * Kapitan z flotą startową na sektorze otwartego oceanu zadanego akwenu,
     * z odkrytym tylko sektorem pozycji. Strażnik cieśniny pokonany.
     *
     * @param list<Technology> $researched
     */
    private function captain(KernelBrowser $client, string $sea, array $researched = [], int $tick = 0): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $graph = static::getContainer()->get(SeaGraph::class);
        $profile = $repository->get(new ProfileId($profileId));
        $position = static::getContainer()->get(WorldFactory::class)->mapOf($graph->sea($sea), $profile)->gatePosition(self::GATE);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 600, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $position, ["{$position['x']}:{$position['y']}"],
            tick: $tick,
            researched: $researched,
            sea: $sea,
            openedGates: [StaticSeaGraph::GUARDED_STRAIT => 0],
        ));

        return $profileId;
    }

    /**
     * Kapitan w Zatoce Rozbitka z uczelnią i magazynem 100/100/100.
     *
     * @param list<Technology> $researched
     */
    private function scholar(KernelBrowser $client, int $xp, array $researched): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->mapOf(static::getContainer()->get(SeaGraph::class)->sea(Sea::START), $profile);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), $xp, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('zatoka-rozbitka'),
            stockpiles: ['zatoka-rozbitka' => new Resources(100, 100, 100)],
            depositTicks: ['zatoka-rozbitka' => 0],
            ownedIslands: ['zatoka-rozbitka' => 0],
            buildings: ['zatoka-rozbitka' => [BuildingType::Uczelnia]],
            researched: $researched,
        ));

        return $profileId;
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
