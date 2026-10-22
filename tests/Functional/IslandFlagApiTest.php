<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class IslandFlagApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFirstWinRaisesFlagOnIsland(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $expedition = $this->expedition($client, $profileId);
        self::assertSame(0, $expedition['profile']['ownedIslands']);
        self::assertSame([], array_keys(array_filter(array_column($expedition['islands'], 'owned', 'id'))));

        // kapitan zakotwiczony na Mieliznach (złoże drewna), jeszcze bez bandery
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('mielizny'),
        ));
        $kuterId = $profile->fleet()[0]->id;

        $settled = $this->winBattle($client, $profileId, $kuterId);
        self::assertTrue($settled['captured']);

        $expedition = $this->expedition($client, $profileId);
        $islands = array_column($expedition['islands'], null, 'id');
        self::assertSame(1, $expedition['profile']['ownedIslands']);
        self::assertTrue($islands['mielizny']['owned']);
        self::assertTrue($islands['mielizny']['deposit']['active']);
        self::assertFalse($islands['zatoka-rozbitka']['owned']);
        self::assertFalse($islands['archipelag-mgiel']['deposit']['active']);

        // zdobyta wyspa nie jest areną: bez kontrataku nie przyjmuje bitwy (#78)
        $again = $this->post($client, "/api/profiles/$profileId/islands/mielizny/battle", ['shipIds' => [$kuterId]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_COUNTERATTACK', $again['error']['code']);
        self::assertSame(1, $this->expedition($client, $profileId)['profile']['ownedIslands']);
    }

    /**
     * Sam kuter: przeciwnik dostaje lustrzany kuter na (0,0) poziomo (generator
     * testowy), więc dwa strzały wygrywają, zanim AI zatopi nasz kuter.
     *
     * @return array<string,mixed> wynik rozliczenia
     */
    private function winBattle(KernelBrowser $client, string $profileId, string $kuterId): array
    {
        $gameId = $this->post($client, "/api/profiles/$profileId/islands/mielizny/battle", ['shipIds' => [$kuterId]])['id'];
        self::assertResponseStatusCodeSame(201);
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2]]]);
        self::assertResponseIsSuccessful();
        foreach ([[0, 0], [1, 0]] as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
        }

        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();
        self::assertSame('won', $settled['result']);

        return $settled;
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
