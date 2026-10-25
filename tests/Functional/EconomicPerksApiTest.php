<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Encounter;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\Specializations;
use App\Domain\Expedition\SpecializationSource;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Perki ścieżki ekonomicznej (#162) przez API: rynek z reputacją, ładownia, budowa, magazyn złóż, handlarz. */
final class EconomicPerksApiTest extends WebTestCase
{
    /** Punkty ekonomiczne na poziom (D2: progi 20, 60, 120, 200, 300). */
    private const LEVEL_POINTS = [0, 20, 60, 120, 200, 300];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMasterTraderPricesCombineWithReputationAtThePort(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, 5, 'port', new Resources(gold: 10), reputation: 40);

        $expedition = $this->expedition($client, $profileId);
        $port = $expedition['world']['tradePort'];
        // kupiec (90%) × mistrz handlu (90%) = 81%
        self::assertSame(90, $port['perkPercent']);
        self::assertSame(81, $port['pricePercent']);
        self::assertSame(['wood' => ['buy' => 6, 'sell' => 9], 'steel' => ['buy' => 3, 'sell' => 5]], $port['rate']);
        // kuter 15 i niszczyciel 20, oba +10%
        self::assertSame(38, $expedition['profile']['cargoCapacity']);

        // 5 złota × 5 drewna × 100 / 81 = 30; w ładowni 5 złota + 30 drewna = 35 z 38
        $traded = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/trade", ['side' => 'buy', 'good' => 'wood', 'gold' => 5]);
        self::assertResponseIsSuccessful();
        self::assertSame(30, $traded['received']['wood']);
    }

    public function testBelowLevelTwoThePortChargesReputationPriceOnly(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, 1, 'port', Resources::none(), reputation: 40);

        $port = $this->expedition($client, $profileId)['world']['tradePort'];
        self::assertSame(100, $port['perkPercent']);
        self::assertSame(90, $port['pricePercent']);
    }

    public function testCheaperShipbuildingAndLargerDepositStorage(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, 4, 'zatoka-rozbitka', Resources::none(), stock: new Resources(wood: 18));

        $expedition = $this->expedition($client, $profileId);
        $types = array_column($expedition['shipTypes'], null, 'type');
        self::assertSame(['wood' => 18, 'steel' => 0, 'gold' => 0], $types['kuter']['buildCost']);
        self::assertSame(['wood' => 27, 'steel' => 18, 'gold' => 0], $types['niszczyciel']['buildCost']);
        // Mielizny: 3 drewna na tik × (10 + 5) tików
        $mielizny = array_column($expedition['islands'], null, 'id')['mielizny'];
        self::assertSame(['wood' => 45, 'steel' => 0, 'gold' => 0], $mielizny['deposit']['cap']);

        $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'kuter', 'islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(201);
        $zatoka = array_column($this->expedition($client, $profileId)['islands'], null, 'id')['zatoka-rozbitka'];
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $zatoka['stockpile']);
    }

    public function testBelowLevelThreeShipsCostFullPrice(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, 2, 'zatoka-rozbitka', Resources::none(), stock: new Resources(wood: 18));

        $types = array_column($this->expedition($client, $profileId)['shipTypes'], null, 'type');
        self::assertSame(['wood' => 20, 'steel' => 0, 'gold' => 0], $types['kuter']['buildCost']);
        $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'kuter', 'islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(409);
    }

    public function testMerchantDropsSurchargeOnlyAtLevelFive(): void
    {
        $client = static::createClient();
        $level4 = $this->captain($client, 4, 'sea', Resources::none(), merchant: true);
        $level5 = $this->captain($client, 5, 'sea', new Resources(gold: 4), merchant: true);

        $trade4 = $this->expedition($client, $level4)['encounter']['trade'];
        self::assertSame(25, $trade4['worsePercent']);
        self::assertNotSame($trade4['portRate'], $trade4['rate']);

        $trade5 = $this->expedition($client, $level5)['encounter']['trade'];
        self::assertSame(0, $trade5['worsePercent']);
        self::assertSame($trade5['portRate'], $trade5['rate']);
        // 90%: 5 drewna za złoto, jak w porcie
        $traded = $this->post($client, "/api/profiles/$level5/encounter/trade", ['buy' => ['wood' => 20]]);
        self::assertResponseIsSuccessful();
        self::assertSame(4, $traded['goldPaid']);
    }

    /**
     * Kapitan na danym poziomie ekonomicznym z kutrem i niszczycielem: w porcie handlowym ('port'),
     * na otwartym morzu ('sea', opcjonalnie z handlarzem) albo przy wyspie o danym id.
     */
    private function captain(KernelBrowser $client, int $level, string $where, Resources $cargo, int $reputation = 0, ?Resources $stock = null, bool $merchant = false): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId)) ?? throw new \LogicException('Profile not found');
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $at = match ($where) {
            'port' => $world->tradePortPosition() ?? throw new \LogicException('Sea without trade port'),
            'sea' => $this->openWater($world),
            default => $profile->position(),
        };

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [],
            [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Niszczyciel)],
            $cargo, $profile->worldSeed(), $at, ["{$at['x']}:{$at['y']}"],
            tick: 0,
            stockpiles: null !== $stock ? [$where => $stock] : [],
            encounter: $merchant ? new Encounter(EncounterType::Merchant, 0, Sea::START, $at['x'], $at['y']) : null,
            reputation: $reputation,
            specializations: (new Specializations())->add(SpecializationSource::Discovery, self::LEVEL_POINTS[$level]),
        ));

        return $profileId;
    }

    /** @return array{x:int,y:int} */
    private function openWater(WorldMap $world): array
    {
        for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
            for ($x = 0; $x < WorldMap::WIDTH; ++$x) {
                if (null === $world->islandAt($x, $y) && null === $world->gateAt($x, $y) && $world->tradePortPosition() !== ['x' => $x, 'y' => $y]) {
                    return ['x' => $x, 'y' => $y];
                }
            }
        }
        self::fail('No open waters');
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
