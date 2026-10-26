<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Encounter;
use App\Domain\Expedition\EncounterType;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** „Zmień skład” (#166): anulowanie nierozstawionej bitwy wyprawy przez API. */
final class CancelBattleApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testCancelledIslandBattleFreesTheShipsAndCanStartAgainWithAnotherSquad(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $fleet = array_column($this->expedition($client, $profileId)['fleet'], 'id');

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle")['id'];
        self::assertResponseStatusCodeSame(201);
        self::assertSame(1, $this->expedition($client, $profileId)['world']['tick']);

        $cancelled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/cancel");
        self::assertResponseIsSuccessful();
        self::assertEqualsCanonicalizing($fleet, $cancelled['ships']);

        // gra znika, a bitwa z rejestru; rozliczenie nie odpowiada 409 „trwa”, więc front sprząta sesję; tik nie wraca
        $client->request('GET', "/api/games/$gameId");
        self::assertResponseStatusCodeSame(404);
        $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertNotSame(409, $client->getResponse()->getStatusCode());
        self::assertFalse($client->getResponse()->isSuccessful());
        self::assertSame(1, $this->expedition($client, $profileId)['world']['tick']);

        $again = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle", ['shipIds' => [$fleet[0]]]);
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', '/api/games/'.$again['id']);
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('pending', $view['status']);
        self::assertSame(2, $this->expedition($client, $profileId)['world']['tick']);
    }

    public function testPlacedBattleCannotBeCancelledAfterTheFirstShot(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $gameId = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle")['id'];

        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 2],
            ['x' => 4, 'y' => 0, 'o' => 'h', 'l' => 1],
            ['x' => 6, 'y' => 2, 'o' => 'h', 'l' => 1],
            ['x' => 0, 'y' => 4, 'o' => 'h', 'l' => 1],
        ]]);
        self::assertResponseIsSuccessful();
        $this->post($client, "/api/games/$gameId/shots", ['x' => 6, 'y' => 6]);
        self::assertResponseIsSuccessful();

        $refused = $this->post($client, "/api/profiles/$profileId/battles/$gameId/cancel");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('BATTLE_ALREADY_STARTED', $refused['error']['code']);

        // bitwa trwa dalej: rozliczenie czeka na koniec gry
        $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseStatusCodeSame(409);
        $client->request('GET', "/api/games/$gameId");
        self::assertResponseIsSuccessful();
    }

    public function testCancelledDefenseCanBeTakenUpAgain(): void
    {
        $client = static::createClient();
        $profileId = $this->captainOnOwnedIsland($client, tick: 25, pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null]);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle")['id'];
        self::assertResponseStatusCodeSame(201);
        self::assertTrue($this->expedition($client, $profileId)['counterattack']['pending']['defenseStarted']);

        $this->post($client, "/api/profiles/$profileId/battles/$gameId/cancel");
        self::assertResponseIsSuccessful();
        $pending = $this->expedition($client, $profileId)['counterattack']['pending'];
        self::assertFalse($pending['defenseStarted']);
        self::assertSame(6, $pending['ticksLeft']);

        $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle");
        self::assertResponseStatusCodeSame(201);
    }

    public function testDefenseCancelledPastTheStrikeResolvesTheCounterattackAtOnce(): void
    {
        $client = static::createClient();
        $profileId = $this->captainOnOwnedIsland($client, tick: 31, pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null]);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle")['id'];
        self::assertSame(0, $this->expedition($client, $profileId)['counterattack']['pending']['ticksLeft']);

        $this->post($client, "/api/profiles/$profileId/battles/$gameId/cancel");
        self::assertResponseIsSuccessful();

        $expedition = $this->expedition($client, $profileId);
        self::assertNull($expedition['counterattack']['pending']);
        self::assertFalse($expedition['counterattack']['last']['held']);
        self::assertTrue($expedition['counterattack']['last']['absent']);
        $islands = array_column($expedition['islands'], null, 'id');
        self::assertFalse($islands['wyspa-sygnalowa']['owned']);
    }

    public function testCancelledRaidRestoresTheMerchantAndTheReputation(): void
    {
        $client = static::createClient();
        $profileId = $this->captainMeetingMerchant($client);
        $before = $this->expedition($client, $profileId);

        $gameId = $this->post($client, "/api/profiles/$profileId/encounter/battle")['id'];
        self::assertResponseStatusCodeSame(201);
        self::assertNull($this->expedition($client, $profileId)['encounter']);

        $this->post($client, "/api/profiles/$profileId/battles/$gameId/cancel");
        self::assertResponseIsSuccessful();

        $after = $this->expedition($client, $profileId);
        self::assertSame('merchant', $after['encounter']['type']);
        self::assertSame($before['encounter']['position'], $after['encounter']['position']);
        self::assertSame($before['profile']['reputation'], $after['profile']['reputation']);
        self::assertSame($before['world']['tick'] + 1, $after['world']['tick']);
    }

    public function testTrainingGameIsNotAnExpeditionBattle(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $gameId = $this->post($client, '/api/games', ['mode' => 'classic'])['id'];

        $refused = $this->post($client, "/api/profiles/$profileId/battles/$gameId/cancel");
        self::assertResponseStatusCodeSame(404);
        self::assertSame('BATTLE_NOT_REGISTERED', $refused['error']['code']);
        $client->request('GET', "/api/games/$gameId");
        self::assertResponseIsSuccessful();
    }

    private function captainOnOwnedIsland(KernelBrowser $client, int $tick, array $pending): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 80, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('wyspa-sygnalowa'), null, 0, $tick, null,
            [], null, [], ['wyspa-sygnalowa' => 0],
            ['pending' => $pending, 'calmFrom' => 0, 'rolledTo' => $tick - 1, 'last' => null],
        ));

        return $profileId;
    }

    private function captainMeetingMerchant(KernelBrowser $client): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $at = $this->openSea($world);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $at, ["{$at['x']}:{$at['y']}"],
            tick: 0,
            encounter: new Encounter(EncounterType::Merchant, 0, Sea::START, $at['x'], $at['y']),
        ));

        return $profileId;
    }

    /** @return array{x:int,y:int} */
    private function openSea(WorldMap $world): array
    {
        for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
            for ($x = 0; $x < WorldMap::WIDTH; ++$x) {
                if (null === $world->islandAt($x, $y) && null === $world->gateAt($x, $y)) {
                    return ['x' => $x, 'y' => $y];
                }
            }
        }
        self::fail('No open sea');
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
