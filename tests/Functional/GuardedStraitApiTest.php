<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Strzeżona cieśnina i Ocean Sztormów (#107) przez API. */
final class GuardedStraitApiTest extends WebTestCase
{
    private const STRAIT = StaticSeaGraph::GUARDED_STRAIT;
    private const OCEAN = StaticSeaGraph::STORM_OCEAN;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testGuardianBattleNeedsLieutenantRankAndTheFleetAtTheGate(): void
    {
        $client = static::createClient();

        [$sternik] = $this->captain($client, xp: 399);
        $gate = $this->expedition($client, $sternik)['world']['gates'][0];
        self::assertSame(self::STRAIT, $gate['id']);
        self::assertSame('Strzeżona cieśnina', $gate['name']);
        self::assertSame(['id' => self::OCEAN, 'name' => 'Ocean Sztormów', 'theme' => ['stormChance' => 40, 'visibility' => 1, 'depositMultiplier' => 2]], $gate['to']);
        self::assertSame('east', $gate['edge']);
        self::assertTrue($gate['present']);
        self::assertSame('rank', $gate['blocker']);
        self::assertFalse($gate['guardian']['unlocked']);
        self::assertSame(17, $gate['guardian']['fleetCells']);

        $refused = $this->post($client, "/api/profiles/$sternik/gates/".self::STRAIT.'/battle');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('GATE_LOCKED', $refused['error']['code']);

        [$away] = $this->captain($client, xp: 400, atGate: false);
        $gate = $this->expedition($client, $away)['world']['gates'][0];
        self::assertSame('guardian', $gate['blocker']);
        // sektor bramy poza odkrytą mapą nie zdradza położenia
        self::assertFalse($gate['discovered']);
        self::assertNull($gate['position']);
        $refused = $this->post($client, "/api/profiles/$away/gates/".self::STRAIT.'/battle');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_GATE', $refused['error']['code']);

        [$porucznik] = $this->captain($client, xp: 400);
        $refused = $this->post($client, "/api/profiles/$porucznik/gates/".self::STRAIT.'/pass');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('GATE_GUARDED', $refused['error']['code']);

        $unknown = $this->post($client, "/api/profiles/$porucznik/gates/brama-donikad/battle");
        self::assertResponseStatusCodeSame(404);
        self::assertSame('GATE_NOT_FOUND', $unknown['error']['code']);

        $game = $this->post($client, "/api/profiles/$porucznik/gates/".self::STRAIT.'/battle');
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['w' => 12, 'h' => 12], $game['board']);
        self::assertSame('fun', $game['ruleset']);

        // wróg płynie stałą flotą strażnika, nie lustrem składu gracza (kuter + 3 tratwy)
        $client->request('GET', "/api/games/{$game['id']}");
        $state = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([2 => 1, 1 => 3], $state['allowedShips']);
        self::assertSame([4 => 1, 3 => 2, 2 => 2, 1 => 3], $state['opponentShips']);
        self::assertSame(1, $this->expedition($client, $porucznik)['world']['tick']);
    }

    public function testGuardianBattleSettlesIntoAnOpenGateOrAnotherTry(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captain($client, xp: 400, cargo: Resources::none());

        $gameId = $this->post($client, "/api/profiles/$profileId/gates/".self::STRAIT.'/battle')['id'];
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2], ['x' => 5, 'y' => 7, 'o' => 'h', 'l' => 1],
            ['x' => 7, 'y' => 7, 'o' => 'h', 'l' => 1], ['x' => 9, 'y' => 9, 'o' => 'h', 'l' => 1],
        ]]);
        self::assertResponseIsSuccessful();

        // flota strażnika w generatorze testowym: wiersze 0 i 2, najdłuższe pierwsze, odstęp 1 pola
        $cells = [[0, 0], [1, 0], [2, 0], [3, 0], [5, 0], [6, 0], [7, 0], [9, 0], [10, 0], [11, 0], [0, 2], [1, 2], [3, 2], [4, 2], [6, 2], [8, 2], [10, 2]];
        foreach ($cells as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
        }
        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();

        // AI strzela losowo — wynik jako niezmiennik: wygrana otwiera bramę, przegrana nie
        $won = 'won' === $settled['result'];
        self::assertSame($won, $settled['gateOpened']);
        self::assertFalse($settled['captured']);
        self::assertNull($settled['stockpile']);
        self::assertSame($won ? 200 : 50, $settled['awarded']);
        self::assertSame($won ? ['wood' => 40, 'steel' => 40, 'gold' => 20] : ['wood' => 10, 'steel' => 0, 'gold' => 0], $settled['loot']);
        // łup w ładowni, nadmiar za burtą — razem cały łup
        self::assertSame($settled['loot'], Resources::fromArray($settled['cargo'])->add(Resources::fromArray($settled['cargoLost']))->toArray());

        $gate = $this->expedition($client, $profileId)['world']['gates'][0];
        self::assertSame($won ? null : 'guardian', $gate['blocker']);
        self::assertSame($won, $gate['guardian']['defeated']);
        self::assertSame(['wins' => $won ? 1 : 0, 'losses' => $won ? 0 : 1], ['wins' => $gate['guardian']['wins'], 'losses' => $gate['guardian']['losses']]);

        $again = $this->post($client, "/api/profiles/$profileId/gates/".self::STRAIT.'/battle');
        self::assertResponseStatusCodeSame($won ? 409 : 201);
        if ($won) {
            self::assertSame('GATE_ALREADY_OPEN', $again['error']['code']);
        }
    }

    public function testOpenGateLeadsToTheStormOceanAndBackForOneTickEach(): void
    {
        $client = static::createClient();
        [$profileId, $west] = $this->captain($client, xp: 400, opened: true, tick: 10);

        $passed = $this->post($client, "/api/profiles/$profileId/gates/".self::STRAIT.'/pass');
        self::assertResponseIsSuccessful();
        self::assertSame(self::OCEAN, $passed['sea']['id']);
        self::assertSame($west, $passed['position']);
        self::assertSame(self::STRAIT, $passed['atGate']);
        self::assertSame(11, $passed['tick']);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame(['id' => self::OCEAN, 'name' => 'Ocean Sztormów', 'theme' => ['stormChance' => 40, 'visibility' => 1, 'depositMultiplier' => 2]], $expedition['world']['sea']);
        self::assertSame(40, $expedition['world']['stormChance']);
        self::assertSame(['przystan-wichrow', 'rafa-kotwiczna', 'wyspa-latarnikow', 'zatoka-burz', 'bastion-fal'], array_column($expedition['islands'], 'id'));
        $back = $expedition['world']['gates'][0];
        self::assertSame(Sea::START, $back['to']['id']);
        self::assertSame('west', $back['edge']);
        self::assertTrue($back['present']);
        self::assertNull($back['blocker']);

        // wyspa Akwenu 1 nie leży na mapie Oceanu
        $refused = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_ISLAND', $refused['error']['code']);

        $home = $this->post($client, "/api/profiles/$profileId/gates/".self::STRAIT.'/pass');
        self::assertResponseIsSuccessful();
        self::assertSame(Sea::START, $home['sea']['id']);
        self::assertSame(12, $home['tick']);
        self::assertSame(20, $this->expedition($client, $profileId)['world']['stormChance']);
    }

    public function testOceanIslandIsCapturedAndItsDepositYieldsDouble(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captain($client, xp: 400, opened: true, sea: self::OCEAN, atIsland: 'rafa-kotwiczna', owned: ['rafa-kotwiczna' => 0], tick: 2);

        $rafa = array_column($this->expedition($client, $profileId)['islands'], null, 'id')['rafa-kotwiczna'];
        self::assertTrue($rafa['present']);
        self::assertTrue($rafa['owned']);
        // 3 stali/tik ×2 z motywu Oceanu, za 2 tiki pod banderą
        self::assertSame(['yield' => ['wood' => 0, 'steel' => 6, 'gold' => 0], 'cap' => ['wood' => 0, 'steel' => 60, 'gold' => 0], 'active' => true], $rafa['deposit']);
        self::assertSame(['wood' => 0, 'steel' => 12, 'gold' => 0], $rafa['stockpile']);

        [$attacker] = $this->captain($client, xp: 400, opened: true, sea: self::OCEAN, atIsland: 'wyspa-latarnikow');
        $game = $this->post($client, "/api/profiles/$attacker/islands/wyspa-latarnikow/battle");
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['w' => 11, 'h' => 11], $game['board']);
    }

    /**
     * Kapitan z flotą startową na sektorze strzeżonej cieśniny (albo przy wyspie)
     * zadanego akwenu, z odkrytym sektorem pozycji.
     *
     * @param array<string, int> $owned
     *
     * @return array{0: string, 1: array{x:int,y:int}} [id profilu, sektor bramy po stronie Oceanu]
     */
    private function captain(
        KernelBrowser $client,
        int $xp,
        bool $atGate = true,
        bool $opened = false,
        string $sea = Sea::START,
        ?string $atIsland = null,
        array $owned = [],
        int $tick = 0,
        ?Resources $cargo = null,
    ): array {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $graph = static::getContainer()->get(SeaGraph::class);
        $factory = static::getContainer()->get(WorldFactory::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = $factory->mapOf($graph->sea($sea), $profile);
        $ocean = $factory->mapOf($graph->sea(self::OCEAN), $profile);

        $position = match (true) {
            null !== $atIsland => $world->islandPosition($atIsland),
            $atGate => $world->gatePosition(self::STRAIT),
            default => ['x' => 0, 'y' => 0 === $world->gatePosition(self::STRAIT)['y'] ? 11 : 0],
        };
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), $xp, [], $profile->fleet(), $cargo ?? Resources::none(),
            $profile->worldSeed(), $position, ["{$position['x']}:{$position['y']}"],
            tick: $tick,
            depositTicks: $owned,
            ownedIslands: $owned,
            sea: $sea,
            openedGates: $opened ? [self::STRAIT => 0] : [],
        ));

        return [$profileId, $ocean->gatePosition(self::STRAIT)];
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
