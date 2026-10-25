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

/** Reputacja kupiec ↔ korsarz (#142): stan w HUD, ceny i embargo na rynku, napad i łowcy nagród. */
final class ReputationApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testNewCaptainIsNeutralAndSeesEffectsAndThresholdsUpFront(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        self::assertSame([
            'value' => 0,
            'tier' => 'neutral',
            'pricePercent' => 100,
            'embargo' => false,
            'min' => -100,
            'max' => 100,
            'changes' => ['trade' => 2, 'pirateDefeated' => 5, 'raid' => -20],
            'thresholds' => ['merchant' => 40, 'corsair' => -40, 'embargo' => -70],
            'prices' => ['merchant' => 90, 'corsair' => 125],
        ], $this->expedition($client, $profileId)['profile']['reputation']);
    }

    public function testMerchantGetsPortDiscountAndEachTradeRaisesReputation(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtPort($client, 40, new Resources(gold: 10));

        $port = $this->expedition($client, $profileId)['world']['tradePort'];
        self::assertSame(90, $port['pricePercent']);
        self::assertFalse($port['embargo']);
        self::assertSame(['wood' => ['buy' => 5, 'sell' => 10], 'steel' => ['buy' => 3, 'sell' => 6]], $port['baseRate']);
        self::assertSame(['wood' => ['buy' => 5, 'sell' => 9], 'steel' => ['buy' => 3, 'sell' => 6]], $port['rate']);

        // kupno od całego zlecenia: 10 złota × 5 drewna / 90% = 55 drewna
        $bought = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/trade", ['side' => 'buy', 'good' => 'wood', 'gold' => 10]);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 55, 'steel' => 0, 'gold' => 0], $bought['received']);
        self::assertSame(42, $this->expedition($client, $profileId)['profile']['reputation']['value']);
    }

    public function testEmbargoClosesTheMarketButNotThePortStockpile(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtPort($client, -70, new Resources(wood: 20, gold: 10));
        $expedition = $this->expedition($client, $profileId);
        self::assertTrue($expedition['profile']['reputation']['embargo']);
        self::assertSame('embargo', $expedition['profile']['reputation']['tier']);
        self::assertTrue($expedition['world']['tradePort']['embargo']);
        self::assertSame(125, $expedition['world']['tradePort']['pricePercent']);

        $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/trade", ['side' => 'sell', 'good' => 'wood', 'gold' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('EMBARGO', $this->errorCode($client));
        $kuter = $expedition['fleet'][1]['id'];
        $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/ships/$kuter/sell");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('EMBARGO', $this->errorCode($client));

        // własny magazyn w porcie zostaje dostępny: embargo blokuje handel, nie przeładunek
        $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'targ-rozbitkow', 'direction' => 'unload', 'wood' => 5]);
        self::assertResponseIsSuccessful();
        self::assertSame(-70, $this->expedition($client, $profileId)['profile']['reputation']['value']);
    }

    public function testRaidCostsReputationAtBattleStartAndTheBannerSaysSo(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtSea($client, -45, EncounterType::Merchant);

        $encounter = $this->expedition($client, $profileId)['encounter'];
        self::assertSame(['battle' => -20, 'trade' => 2], $encounter['reputation']);

        $this->post($client, "/api/profiles/$profileId/encounter/battle");
        self::assertResponseStatusCodeSame(201);
        $expedition = $this->expedition($client, $profileId);
        self::assertSame(-65, $expedition['profile']['reputation']['value']);
        self::assertSame('corsair', $expedition['profile']['reputation']['tier']);
    }

    public function testBountyHuntersLeaveOnlyTheBattle(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtSea($client, -80, EncounterType::BountyHunters);

        $encounter = $this->expedition($client, $profileId)['encounter'];
        self::assertSame('bounty-hunters', $encounter['type']);
        self::assertSame('Łowcy nagród', $encounter['battle']['name']);
        self::assertSame(['niszczyciel', 'kuter', 'kuter', 'tratwa', 'niszczyciel'], $encounter['battle']['fleet']);
        self::assertSame([75, 18], [$encounter['battle']['xpWin'], $encounter['battle']['xpLoss']]);
        self::assertSame(['wood' => 0, 'steel' => 13, 'gold' => 19], $encounter['battle']['lootWin']);
        self::assertNull($encounter['trade']);
        self::assertNull($encounter['flee']);
        self::assertNull($encounter['ransom']);
        self::assertSame(['battle' => 0, 'trade' => null], $encounter['reputation']);

        foreach (['pass', 'flee', 'ransom'] as $decision) {
            $this->post($client, "/api/profiles/$profileId/encounter/decide", ['decision' => $decision]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('ENCOUNTER_CHOICE_UNAVAILABLE', $this->errorCode($client));
        }
        $this->post($client, "/api/profiles/$profileId/encounter/trade", ['sell' => ['wood' => 13]]);
        self::assertSame('ENCOUNTER_CHOICE_UNAVAILABLE', $this->errorCode($client));

        $this->post($client, "/api/profiles/$profileId/encounter/battle");
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->expedition($client, $profileId)['encounter']);
    }

    /** Nowy profil z reputacją, zakotwiczony w porcie handlowym Morza Rozbitków (transportowiec i kuter). */
    private function captainAtPort(KernelBrowser $client, int $reputation, Resources $cargo): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $port = static::getContainer()->get(WorldFactory::class)->worldFor($profile)->tradePortPosition();
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [],
            [OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Kuter)], $cargo,
            $profile->worldSeed(), $port, ["{$port['x']}:{$port['y']}"],
            reputation: $reputation,
        ));

        return $profileId;
    }

    /** Nowy profil z reputacją na otwartym morzu, ze spotkaniem czekającym na decyzję. */
    private function captainAtSea(KernelBrowser $client, int $reputation, EncounterType $type): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $at = $this->openWater(static::getContainer()->get(WorldFactory::class)->worldFor($profile));
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [], $profile->fleet(), new Resources(wood: 20, gold: 20),
            $profile->worldSeed(), $at, ["{$at['x']}:{$at['y']}"],
            tick: 0,
            encounter: new Encounter($type, 0, Sea::START, $at['x'], $at['y']),
            reputation: $reputation,
        ));

        return $profileId;
    }

    /** @return array{x:int, y:int} */
    private function openWater(WorldMap $world): array
    {
        for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
            for ($x = 0; $x < WorldMap::WIDTH; ++$x) {
                if (null === $world->islandAt($x, $y) && null === $world->gateAt($x, $y) && $world->tradePortPosition() !== ['x' => $x, 'y' => $y]) {
                    return ['x' => $x, 'y' => $y];
                }
            }
        }
        throw new \LogicException('No open water');
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
