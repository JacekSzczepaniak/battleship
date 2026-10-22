<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\ShipType;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class BattleSquadApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFleetOverIslandLimitSailsAsDefaultSquad(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        // darmowe tratwy: kuter + 3 tratwy + 7 nowych = 12 pól > 9 (Zatoka, 7×7)
        for ($i = 0; $i < 7; ++$i) {
            $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'tratwa', 'islandId' => 'zatoka-rozbitka']);
            self::assertResponseStatusCodeSame(201);
        }

        $client->request('GET', "/api/profiles/$profileId/expedition");
        $expedition = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(9, $expedition['islands'][0]['squadLimit']);

        // bez wskazania składu: dobór domyślny w limicie, przeciwnik lustrzany
        $battle = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle");
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', "/api/games/{$battle['id']}");
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['2' => 1, '1' => 7], $view['allowedShips']); // kuter + 7 tratw = 9 pól
    }

    public function testExplicitSquadOverLimitIsRejected(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        for ($i = 0; $i < 7; ++$i) {
            $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'tratwa', 'islandId' => 'zatoka-rozbitka']);
        }
        $client->request('GET', "/api/profiles/$profileId/expedition");
        $fleet = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['fleet'];
        $allIds = array_column($fleet, 'id');

        $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle", ['shipIds' => $allIds]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SQUAD_TOO_LARGE', $this->lastErrorCode($client));

        // wskazany skład w limicie: tylko kuter
        $kuterId = $fleet[array_search('kuter', array_column($fleet, 'type'), true)]['id'];
        $battle = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle", ['shipIds' => [$kuterId]]);
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', "/api/games/{$battle['id']}");
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['2' => 1], $view['allowedShips']);
    }

    public function testTransportShipSailsOnlyWhenPickedAndEscorted(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        // flota z transportowcem — bez API stoczni (wymaga Marynarza i stoczni poziomu 2)
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        [$transport, $kuter, $tratwa] = [OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Tratwa)];
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [], [$transport, $kuter, $tratwa], $profile->cargo(),
            $profile->worldSeed(), $profile->position(), $profile->discoveredSectors(), visited: $profile->visitedSectors(),
        ));

        // dobór domyślny pomija transportowiec, choć mieści się w limicie 9 pól
        $battle = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle");
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['2' => 1, '1' => 1], $this->allowedShips($client, $battle['id']));

        // konwój bez eskorty nie atakuje
        $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle", ['shipIds' => [$transport->id, $tratwa->id]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('CONVOY_UNESCORTED', $this->lastErrorCode($client));

        // z kutrem w eskorcie płynie jako zwykły cel; przeciwnik dostaje lustrzany skład
        $battle = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle", ['shipIds' => [$transport->id, $kuter->id]]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['5' => 1, '2' => 1], $this->allowedShips($client, $battle['id']));
    }

    /** @return array<string,int> */
    private function allowedShips(KernelBrowser $client, string $gameId): array
    {
        $client->request('GET', "/api/games/$gameId");

        return json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['allowedShips'];
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
