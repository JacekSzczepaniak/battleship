<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ResourceIslandsApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testDepositIslandProducesButHasNoShipyard(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        // kapitan zakotwiczony na Mieliznach, wygrał o nie bitwę na tiku 4, teraz tik 10
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 40,
            [(string) GameId::new() => ['island' => 'mielizny', 'settled' => true, 'result' => 'won']],
            $profile->fleet(), Resources::none(), $profile->worldSeed(), $world->islandPosition('mielizny'),
            tick: 10, depositTicks: ['mielizny' => 4],
        ));

        $client->request('GET', "/api/profiles/$profileId/expedition");
        $expedition = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $islands = array_column($expedition['islands'], null, 'id');

        // 6 tików × 3 drewna czeka w magazynie wyspy
        self::assertSame(['wood' => 18, 'steel' => 0, 'gold' => 0], $islands['mielizny']['stockpile']);
        self::assertSame([
            'yield' => ['wood' => 3, 'steel' => 0, 'gold' => 0],
            'cap' => ['wood' => 30, 'steel' => 0, 'gold' => 0],
            'active' => true,
        ], $islands['mielizny']['deposit']);
        self::assertSame(0, $islands['mielizny']['shipyardLevel']);
        self::assertNull($islands['zatoka-rozbitka']['deposit']);

        // bez stoczni: kutra nie zbudujesz, choć drewna starcza; tratwę tak
        $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'kuter', 'islandId' => 'mielizny']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SHIPYARD_TOO_LOW', $this->lastErrorCode($client));

        $raft = $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'tratwa', 'islandId' => 'mielizny']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('tratwa', $raft['type']);
    }

    public function testFirstWinStartsDepositOnce(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        // kapitan zakotwiczony na Mieliznach (złoże drewna), jeszcze bez wygranej
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('mielizny'),
        ));
        $kuterId = $profile->fleet()[0]->id;

        // sam kuter: przeciwnik dostaje lustrzany kuter na (0,0) poziomo (generator testowy)
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
        self::assertSame(['wood' => 3, 'steel' => 0, 'gold' => 0], $settled['depositStarted']);

        // ponowne rozliczenie nie uruchamia złoża drugi raz
        $again = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertNull($again['depositStarted']);
    }

    private function lastErrorCode(KernelBrowser $client): ?string
    {
        $body = json_decode($client->getResponse()->getContent() ?: '{}', true);

        return is_array($body) ? ($body['error']['code'] ?? null) : null;
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
