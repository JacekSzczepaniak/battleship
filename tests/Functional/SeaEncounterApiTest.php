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
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Spotkania na morzu (#141): baner ze skutkami, blokada rejsu, decyzje i bitwa spotkania. */
final class SeaEncounterApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testPirateBlocksSailingUntilTheCaptainFleesOrPaysRansom(): void
    {
        $client = static::createClient();
        [$profileId, $at, $next] = $this->captain($client, EncounterType::Pirate, new Resources(wood: 50, gold: 9));

        $encounter = $this->expedition($client, $profileId)['encounter'];
        self::assertSame('pirate', $encounter['type']);
        self::assertSame(['id' => Sea::START, 'name' => 'Morze Rozbitków'], $encounter['sea']);
        self::assertSame($at, $encounter['position']);
        self::assertSame(['ticks' => 1, 'cargoLost' => ['wood' => 10, 'steel' => 0, 'gold' => 1]], $encounter['flee']);
        self::assertSame(['gold' => 10, 'affordable' => false], $encounter['ransom']);
        self::assertNull($encounter['trade']);
        self::assertSame(['niszczyciel', 'kuter', 'kuter', 'tratwa'], $encounter['battle']['fleet']);
        self::assertSame(['w' => 8, 'h' => 8], $encounter['battle']['board']);
        self::assertSame(['wood' => 0, 'steel' => 10, 'gold' => 15], $encounter['battle']['lootWin']);

        $this->post($client, "/api/profiles/$profileId/sail", $next);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ENCOUNTER_PENDING', $this->errorCode($client));

        $this->post($client, "/api/profiles/$profileId/encounter/decide", ['decision' => 'ransom']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_ENOUGH_GOLD', $this->errorCode($client));
        $this->post($client, "/api/profiles/$profileId/encounter/decide", ['decision' => 'pass']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ENCOUNTER_CHOICE_UNAVAILABLE', $this->errorCode($client));
        $this->post($client, "/api/profiles/$profileId/encounter/decide", ['decision' => 'surrender']);
        self::assertResponseStatusCodeSame(400);

        $fled = $this->post($client, "/api/profiles/$profileId/encounter/decide", ['decision' => 'flee']);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 10, 'steel' => 0, 'gold' => 1], $fled['cargoLost']);
        self::assertSame(['wood' => 40, 'steel' => 0, 'gold' => 8], $fled['cargo']);
        self::assertSame(1, $fled['tick']);

        $expedition = $this->expedition($client, $profileId);
        self::assertNull($expedition['encounter']);
        self::assertSame('encounter-resolved', $expedition['log'][0]['event']);
        self::assertSame('flee', $expedition['log'][0]['decision']);

        $this->post($client, "/api/profiles/$profileId/sail", $next);
        self::assertResponseIsSuccessful();
    }

    public function testRansomCostsGoldFromTheHold(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captain($client, EncounterType::Pirate, new Resources(wood: 5, gold: 12));

        self::assertTrue($this->expedition($client, $profileId)['encounter']['ransom']['affordable']);
        $paid = $this->post($client, "/api/profiles/$profileId/encounter/decide", ['decision' => 'ransom']);
        self::assertResponseIsSuccessful();
        self::assertSame(10, $paid['goldPaid']);
        self::assertSame(['wood' => 5, 'steel' => 0, 'gold' => 2], $paid['cargo']);
        self::assertSame(0, $paid['tick']);
    }

    public function testMerchantTradesOnTheSpotAtWorsePricesThanThePort(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captain($client, EncounterType::Merchant, new Resources(wood: 40));

        $encounter = $this->expedition($client, $profileId)['encounter'];
        // kurs portu Morza Rozbitków (#140): 5 drewna albo 3 stali za 1 złoto; handlarz o 25% drożej
        self::assertSame(['wood' => ['buy' => 4, 'sell' => 13], 'steel' => ['buy' => 2, 'sell' => 8]], $encounter['trade']['rate']);
        self::assertSame(['wood' => ['buy' => 5, 'sell' => 10], 'steel' => ['buy' => 3, 'sell' => 6]], $encounter['trade']['portRate']);
        self::assertSame([25, 1], [$encounter['trade']['worsePercent'], $encounter['trade']['ticks']]);
        self::assertNull($encounter['flee']);
        self::assertNull($encounter['ransom']);
        self::assertContains('transportowiec', $encounter['battle']['fleet']);

        $this->post($client, "/api/profiles/$profileId/encounter/trade", ['buy' => ['wood' => 20]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_ENOUGH_RESOURCES', $this->errorCode($client));
        $this->post($client, "/api/profiles/$profileId/encounter/trade", ['sell' => ['wood' => -1]]);
        self::assertResponseStatusCodeSame(400);
        $this->post($client, "/api/profiles/$profileId/encounter/decide", ['decision' => 'flee']);
        self::assertSame('ENCOUNTER_CHOICE_UNAVAILABLE', $this->errorCode($client));

        $traded = $this->post($client, "/api/profiles/$profileId/encounter/trade", ['sell' => ['wood' => 40], 'buy' => ['steel' => 6]]);
        self::assertResponseIsSuccessful();
        self::assertSame(3, $traded['goldReceived']);
        self::assertSame(3, $traded['goldPaid']);
        self::assertSame(['wood' => 0, 'steel' => 6, 'gold' => 0], $traded['cargo']);
        self::assertSame(1, $traded['tick']);
        self::assertNull($this->expedition($client, $profileId)['encounter']);

        $this->post($client, "/api/profiles/$profileId/encounter/trade", ['sell' => ['steel' => 6]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_ENCOUNTER', $this->errorCode($client));
    }

    public function testRaidStartsAnOpenSeaBattleThatSettlesLootIntoTheHold(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captain($client, EncounterType::Merchant, Resources::none());

        // do bitwy kuter i tratwy — transportowiec zostaje poza składem
        $squad = array_column(array_filter($this->expedition($client, $profileId)['fleet'], static fn (array $s) => 'transportowiec' !== $s['type']), 'id');
        $game = $this->post($client, "/api/profiles/$profileId/encounter/battle", ['shipIds' => array_values($squad)]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['w' => 8, 'h' => 8], $game['board']);
        $gameId = $game['id'];
        $expedition = $this->expedition($client, $profileId);
        self::assertNull($expedition['encounter']);
        self::assertSame(1, $expedition['world']['tick']);

        // startowa flota: kuter i trzy tratwy
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 2], ['x' => 0, 'y' => 2, 'o' => 'h', 'l' => 1],
            ['x' => 0, 'y' => 4, 'o' => 'h', 'l' => 1], ['x' => 0, 'y' => 6, 'o' => 'h', 'l' => 1],
        ]]);
        self::assertResponseIsSuccessful();
        // ostrzał całej planszy, aż ktoś wygra (AI strzela losowo)
        for ($y = 0; $y < 8; ++$y) {
            for ($x = 0; $x < 8; ++$x) {
                $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
            }
        }

        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();
        $won = 'won' === $settled['result'];
        self::assertSame('encounter', $settled['kind']);
        self::assertSame('merchant', $settled['encounter']);
        self::assertNull($settled['stockpile']);
        self::assertFalse($settled['captured']);
        self::assertSame($won ? 40 : 10, $settled['awarded']);
        self::assertSame($won ? ['wood' => 30, 'steel' => 15, 'gold' => 10] : ['wood' => 0, 'steel' => 0, 'gold' => 0], $settled['loot']);
        // łup w ładowni, nadmiar za burtą — razem cały łup
        self::assertSame($settled['loot'], Resources::fromArray($settled['cargo'])->add(Resources::fromArray($settled['cargoLost']))->toArray());

        $log = $this->expedition($client, $profileId)['log'];
        $battle = array_values(array_filter($log, static fn (array $e) => 'encounter-battle' === $e['event']))[0];
        self::assertSame(['merchant', $settled['result'], 'Morze Rozbitków'], [$battle['encounter'], $battle['result'], $battle['seaName']]);
    }

    public function testNoEncounterMeansNothingToDecide(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        self::assertNull($this->expedition($client, $profileId)['encounter']);
        $this->post($client, "/api/profiles/$profileId/encounter/battle");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_ENCOUNTER', $this->errorCode($client));
    }

    /**
     * Kapitan na otwartym morzu Akwenu 1 ze spotkaniem czekającym na decyzję.
     * Flota: startowa (kuter i trzy tratwy) plus transportowiec na ładunek.
     *
     * @return array{0: string, 1: array{x:int,y:int}, 2: array{x:int,y:int}} [id profilu, sektor spotkania, sąsiedni sektor]
     */
    private function captain(KernelBrowser $client, EncounterType $type, Resources $cargo): array
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        [$at, $next] = $this->openWaters($world);

        $fleet = [...$profile->fleet(), OwnedShip::build(ShipType::Transportowiec)];
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [], $fleet, $cargo,
            $profile->worldSeed(), $at, ["{$at['x']}:{$at['y']}"],
            tick: 0,
            encounter: new Encounter($type, 0, Sea::START, $at['x'], $at['y']),
        ));

        return [$profileId, $at, $next];
    }

    /** @return array{0: array{x:int,y:int}, 1: array{x:int,y:int}} */
    private function openWaters(WorldMap $world): array
    {
        for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
            for ($x = 0; $x < WorldMap::WIDTH - 1; ++$x) {
                if (null === $world->islandAt($x, $y) && null === $world->gateAt($x, $y) && null === $world->islandAt($x + 1, $y) && null === $world->gateAt($x + 1, $y)) {
                    return [['x' => $x, 'y' => $y], ['x' => $x + 1, 'y' => $y]];
                }
            }
        }
        self::fail('No open waters');
    }

    private function errorCode(KernelBrowser $client): ?string
    {
        return json_decode($client->getResponse()->getContent() ?: '{}', true)['error']['code'] ?? null;
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
