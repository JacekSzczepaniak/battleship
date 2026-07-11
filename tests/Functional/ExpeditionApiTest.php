<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

final class ExpeditionApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFullExpeditionLoop(): void
    {
        $client = static::createClient();

        // 1) profil kapitana
        $profile = $this->post($client, '/api/profiles', ['name' => 'Jacek']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('rozbitek', $profile['rank']);
        self::assertSame(0, $profile['xp']);
        $profileId = $profile['id'];

        // 2) stan wyprawy: pierwsza wyspa otwarta, ostatnia zamknięta
        $client->request('GET', "/api/profiles/$profileId/expedition");
        self::assertResponseIsSuccessful();
        $expedition = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $islands = $expedition['islands'];
        self::assertSame('zatoka-rozbitka', $islands[0]['id']);
        self::assertTrue($islands[0]['unlocked']);
        self::assertFalse(end($islands)['unlocked']);
        self::assertSame(['rank' => 'marynarz', 'xpNeeded' => 80], $expedition['profile']['nextRank']);

        // 3) zamknięta wyspa → 403 ISLAND_LOCKED
        $lockedId = end($islands)['id'];
        $this->post($client, "/api/profiles/$profileId/islands/$lockedId/battle");
        self::assertResponseStatusCodeSame(403);

        // 4) bitwa o pierwszą wyspę (classic)
        $battle = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle");
        self::assertResponseStatusCodeSame(201);
        self::assertSame('classic', $battle['ruleset']);
        $gameId = $battle['id'];

        // 5) rozliczenie przed końcem bitwy → 409
        $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseStatusCodeSame(409);

        // 6) wystaw flotę i zatop całą flotę przeciwnika (deterministyczna w env test)
        $fleet = FleetFactory::classic10x10Array();
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => $fleet]);
        self::assertResponseIsSuccessful();

        foreach ($fleet as $s) {
            $horiz = 'h' === strtolower((string) $s['o']);
            for ($i = 0; $i < $s['l']; ++$i) {
                $this->post($client, "/api/games/$gameId/shots", [
                    'x' => $s['x'] + ($horiz ? $i : 0),
                    'y' => $s['y'] + ($horiz ? 0 : $i),
                ]);
                self::assertResponseIsSuccessful();
            }
        }

        // 7) rozliczenie: XP za wygraną wg wyspy
        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();
        self::assertSame('won', $settled['result']);
        self::assertSame(40, $settled['awarded']);
        self::assertSame(40, $settled['xp']);
        self::assertSame('rozbitek', $settled['rank']);
        self::assertFalse($settled['rankUp']);

        // 8) idempotencja: drugie rozliczenie bez XP
        $again = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();
        self::assertSame(0, $again['awarded']);
        self::assertSame(40, $again['xp']);

        // 9) statystyki wyspy w stanie wyprawy
        $client->request('GET', "/api/profiles/$profileId/expedition");
        $expedition = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(1, $expedition['islands'][0]['wins']);
        self::assertSame(40, $expedition['profile']['xp']);
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
