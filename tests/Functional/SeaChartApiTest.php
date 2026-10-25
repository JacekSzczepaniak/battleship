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

/** Mapa akwenu na rynku Morza Mgieł i brama „wody nieznane” do Wód Czarnej Perły (#143) przez API. */
final class SeaChartApiTest extends WebTestCase
{
    private const MIST = StaticSeaGraph::MIST_SEA;
    private const PEARL = StaticSeaGraph::BLACK_PEARL;
    private const GATE = StaticSeaGraph::UNCHARTED_WATERS;

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testChartIsSoldAtTheMistBazaarAndOpensTheGate(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, self::MIST, atPort: true, stock: new Resources(gold: 160));

        $expedition = $this->expedition($client, $profileId);
        self::assertSame(['sea' => self::PEARL, 'seaName' => 'Wody Czarnej Perły', 'price' => 150, 'owned' => false], $expedition['world']['tradePort']['chart']);
        self::assertSame([], $expedition['profile']['seaCharts']);
        $gate = array_column($expedition['world']['gates'], null, 'id')[self::GATE];
        self::assertSame('Wody nieznane', $gate['name']);
        self::assertSame('east', $gate['edge']);
        self::assertSame(['id' => self::PEARL, 'name' => 'Wody Czarnej Perły', 'theme' => ['stormChance' => 30, 'visibility' => 1, 'depositMultiplier' => 3]], $gate['to']);
        self::assertSame('chart', $gate['blocker']);
        self::assertSame(self::PEARL, $gate['chart']);
        self::assertNull($gate['technology']);
        self::assertNull($gate['guardian']);

        $bought = $this->post($client, "/api/profiles/$profileId/trade-ports/bazar-we-mgle/chart");
        self::assertResponseIsSuccessful();
        self::assertSame(self::PEARL, $bought['sea']);
        self::assertSame(150, $bought['gold']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 10], $bought['stockpile']);
        self::assertSame(1, $bought['tick']);

        $again = $this->post($client, "/api/profiles/$profileId/trade-ports/bazar-we-mgle/chart");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('CHART_ALREADY_OWNED', $again['error']['code']);

        $expedition = $this->expedition($client, $profileId);
        self::assertTrue($expedition['world']['tradePort']['chart']['owned']);
        self::assertSame([['sea' => self::PEARL, 'name' => 'Wody Czarnej Perły', 'tick' => 1]], $expedition['profile']['seaCharts']);
        self::assertNull(array_column($expedition['world']['gates'], null, 'id')[self::GATE]['blocker']);
        $last = $expedition['log'][0];
        self::assertSame('chart-acquired', $last['event']);
        self::assertSame('market', $last['source']);
        self::assertSame('Wody Czarnej Perły', $last['seaName']);
        self::assertSame('Bazar we Mgle', $last['islandName']);
    }

    public function testChartIsNotForSaleOutsideTheMistBazaar(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, Sea::START, atPort: true, stock: new Resources(gold: 200));

        self::assertNull($this->expedition($client, $profileId)['world']['tradePort']['chart']);
        $error = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/chart");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_CHART_FOR_SALE', $error['error']['code']);

        // Bazar we Mgle z innego akwenu
        $error = $this->post($client, "/api/profiles/$profileId/trade-ports/bazar-we-mgle/chart");
        self::assertResponseStatusCodeSame(404);
        self::assertSame('TRADE_PORT_NOT_FOUND', $error['error']['code']);
    }

    public function testUnchartedWatersAreLockedWithoutAChart(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, self::MIST);

        $refused = $this->post($client, "/api/profiles/$profileId/gates/".self::GATE.'/pass');
        self::assertResponseStatusCodeSame(403);
        self::assertSame('GATE_LOCKED', $refused['error']['code']);
        $battle = $this->post($client, "/api/profiles/$profileId/gates/".self::GATE.'/battle');
        self::assertResponseStatusCodeSame(409);
        self::assertSame('GATE_HAS_NO_GUARDIAN', $battle['error']['code']);
        self::assertSame(self::MIST, $this->expedition($client, $profileId)['world']['sea']['id']);
    }

    public function testWithAChartTheFleetSailsToTheBlackPearlWatersAndBack(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, self::MIST, charts: [self::PEARL => 0], tick: 10);

        $passed = $this->post($client, "/api/profiles/$profileId/gates/".self::GATE.'/pass');
        self::assertResponseIsSuccessful();
        self::assertSame(self::PEARL, $passed['sea']['id']);
        self::assertSame(self::GATE, $passed['atGate']);
        self::assertSame(11, $passed['tick']);
        self::assertSame(0, $passed['position']['x']);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame(['przystan-kaperska', 'rafa-perlowa', 'wrak-czarnej-perly'], array_column($expedition['islands'], 'id'));
        self::assertNull($expedition['world']['tradePort']);
        $back = $expedition['world']['gates'][0];
        self::assertSame(self::MIST, $back['to']['id']);
        self::assertSame('west', $back['edge']);
        self::assertNull($back['blocker']);

        $home = $this->post($client, "/api/profiles/$profileId/gates/".self::GATE.'/pass');
        self::assertResponseIsSuccessful();
        self::assertSame(self::MIST, $home['sea']['id']);
        self::assertSame(12, $home['tick']);
        self::assertSame(self::PEARL, $this->expedition($client, $profileId)['profile']['seaCharts'][0]['sea']);
    }

    /**
     * Admirał z flotą startową na zadanym akwenie: na sektorze wód nieznanych
     * albo w porcie handlowym akwenu, z odkrytym tylko sektorem pozycji.
     *
     * @param array<string, int> $charts
     */
    private function captain(KernelBrowser $client, string $sea, bool $atPort = false, ?Resources $stock = null, array $charts = [], int $tick = 0): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $target = static::getContainer()->get(SeaGraph::class)->sea($sea);
        $profile = $repository->get(new ProfileId($profileId));
        $map = static::getContainer()->get(WorldFactory::class)->mapOf($target, $profile);
        $position = $atPort ? $map->tradePortPosition() : $map->gatePosition(self::GATE);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 1400, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $position, ["{$position['x']}:{$position['y']}"],
            tick: $tick,
            stockpiles: null !== $stock ? [$target->tradePort->id => $stock] : [],
            sea: $sea,
            seaCharts: $charts,
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
