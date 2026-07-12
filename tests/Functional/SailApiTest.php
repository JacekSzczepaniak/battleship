<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SailApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testSailingRevealsFogAndGatesIslandActions(): void
    {
        $client = static::createClient();

        $profileId = $this->post($client, '/api/profiles', ['name' => 'Nawigator'])['id'];

        // 1) stan świata: pozycja = sektor pierwszej wyspy, mgła częściowo zdjęta
        $client->request('GET', "/api/profiles/$profileId/expedition");
        $expedition = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        $world = $expedition['world'];
        self::assertSame('zatoka-rozbitka', $world['atIsland']);
        self::assertNotEmpty($world['discovered']);
        self::assertTrue($expedition['islands'][0]['discovered']);
        self::assertTrue($expedition['islands'][0]['present']);

        // 2) bitwa o wyspę, przy której NIE stoimy → 409 NOT_AT_ISLAND
        //    (mielizny są odblokowane rangą, ale trzeba do nich dopłynąć)
        $this->post($client, "/api/profiles/$profileId/islands/mielizny/battle");
        self::assertResponseStatusCodeSame(409);

        // 3) żegluga na sąsiedni sektor: mgła schodzi, kartografia płaci
        $pos = $world['position'];
        $target = ['x' => $pos['x'] + ($pos['x'] < $world['width'] - 1 ? 1 : -1), 'y' => $pos['y']];

        $sailed = $this->post($client, "/api/profiles/$profileId/sail", $target);
        self::assertResponseIsSuccessful();
        self::assertSame($target, $sailed['position']);
        self::assertIsInt($sailed['cartography']);
        self::assertIsInt($sailed['materials']);

        // 4) skok przez pół mapy → 409 SAIL_NOT_ADJACENT
        $this->post($client, "/api/profiles/$profileId/sail", [
            'x' => ($target['x'] + 6) % $world['width'], 'y' => $target['y'],
        ]);
        self::assertResponseStatusCodeSame(409);

        // 5) budowa poza wyspą → 409 NOT_AT_ISLAND (zeszliśmy z doku)
        $this->post($client, "/api/profiles/$profileId/ships", [
            'type' => 'tratwa', 'islandId' => 'zatoka-rozbitka',
        ]);
        self::assertResponseStatusCodeSame(409);

        // 6) powrót do doku i budowa darmowej tratwy działa
        $this->post($client, "/api/profiles/$profileId/sail", $pos);
        self::assertResponseIsSuccessful();
        $this->post($client, "/api/profiles/$profileId/ships", [
            'type' => 'tratwa', 'islandId' => 'zatoka-rozbitka',
        ]);
        self::assertResponseStatusCodeSame(201);
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
