<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExpeditionApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFullExpeditionLoop(): void
    {
        $client = static::createClient();

        // 1) profil kapitana z flotą startową (kuter + 3 tratwy) i materiałami
        $profile = $this->post($client, '/api/profiles', ['name' => 'Jacek']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('rozbitek', $profile['rank']);
        $profileId = $profile['id'];

        // 2) stan wyprawy: pierwsza wyspa otwarta, ostatnia zamknięta; flota i stocznie widoczne
        $expedition = $this->getExpedition($client, $profileId);

        $islands = $expedition['islands'];
        self::assertSame('zatoka-rozbitka', $islands[0]['id']);
        self::assertTrue($islands[0]['unlocked']);
        self::assertFalse(end($islands)['unlocked']);
        self::assertSame(['rank' => 'marynarz', 'xpNeeded' => 80], $expedition['profile']['nextRank']);
        self::assertSame(20, $expedition['profile']['materials']);
        self::assertCount(4, $expedition['fleet']);
        self::assertSame(1, $islands[0]['shipyardLevel']);
        self::assertNotEmpty($expedition['shipTypes']);

        // 3) zamknięta wyspa → 403 ISLAND_LOCKED
        $lockedId = end($islands)['id'];
        $this->post($client, "/api/profiles/$profileId/islands/$lockedId/battle");
        self::assertResponseStatusCodeSame(403);

        // 4) bitwa o pierwszą wyspę: plansza 7×7, skład = sprawna flota gracza
        $battle = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle");
        self::assertResponseStatusCodeSame(201);
        self::assertSame('classic', $battle['ruleset']);
        self::assertSame(['w' => 7, 'h' => 7], $battle['board']);
        $gameId = $battle['id'];

        $client->request('GET', "/api/games/$gameId");
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['2' => 1, '1' => 3], $view['allowedShips']);

        // 5) rozliczenie przed końcem bitwy → 409
        $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseStatusCodeSame(409);

        // 6) wystaw flotę (kuter + 3 tratwy) i zatop lustrzaną flotę przeciwnika:
        //    deterministyczny generator pakuje wierszowo → znane pozycje
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 2],
            ['x' => 4, 'y' => 0, 'o' => 'h', 'l' => 1],
            ['x' => 6, 'y' => 2, 'o' => 'h', 'l' => 1],
            ['x' => 0, 'y' => 4, 'o' => 'h', 'l' => 1],
        ]]);
        self::assertResponseIsSuccessful();

        foreach ([[0, 0], [1, 0], [3, 0], [5, 0], [0, 2]] as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
            self::assertResponseIsSuccessful();
        }

        // 7) rozliczenie: XP + materiały wg wyspy; wygrana nie odbiera statków na stałe
        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();
        self::assertSame('won', $settled['result']);
        self::assertSame(40, $settled['awarded']);
        self::assertSame(20, $settled['materialsAwarded']);
        self::assertSame(40, $settled['materials']);
        self::assertSame([], $settled['lostShips']);

        // 8) idempotencja: drugie rozliczenie bez nagród
        $again = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();
        self::assertSame(0, $again['awarded']);
        self::assertSame(0, $again['materialsAwarded']);

        // 9) stocznia: budowa kutra za 20 materiałów
        $ship = $this->post($client, "/api/profiles/$profileId/ships", [
            'type' => 'kuter', 'islandId' => 'zatoka-rozbitka',
        ]);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('kuter', $ship['type']);

        $expedition = $this->getExpedition($client, $profileId);
        self::assertSame(20, $expedition['profile']['materials']);
        self::assertCount(5, $expedition['fleet']);
        self::assertSame(1, $expedition['islands'][0]['wins']);

        // 10) niszczyciel w stoczni poziomu 1 → 409 SHIPYARD_TOO_LOW
        $this->post($client, "/api/profiles/$profileId/ships", [
            'type' => 'niszczyciel', 'islandId' => 'zatoka-rozbitka',
        ]);
        self::assertResponseStatusCodeSame(409);
    }

    public function testSettleRejectsForeignGame(): void
    {
        $client = static::createClient();

        $profileId = $this->post($client, '/api/profiles', [])['id'];

        // gra spoza wyprawy (zwykłe POST /api/games)
        $gameId = $this->post($client, '/api/games', [])['id'];

        $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseStatusCodeSame(404);
    }

    /** @return array<string,mixed> */
    private function getExpedition(KernelBrowser $client, string $profileId): array
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
