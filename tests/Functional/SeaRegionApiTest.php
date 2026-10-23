<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Akweny (#106): bieżący akwen w stanie wyprawy i profile sprzed akwenów. */
final class SeaRegionApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testExpeditionShowsCurrentSeaWithTheme(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $world = $this->expedition($client, $profileId)['world'];

        self::assertSame([
            'id' => 'morze-rozbitkow',
            'name' => 'Morze Rozbitków',
            'theme' => ['stormChance' => 20, 'visibility' => 1, 'depositMultiplier' => 1],
        ], $world['sea']);
        self::assertSame(20, $world['stormChance']);
        self::assertSame(12, $world['width']);
        self::assertSame(12, $world['height']);
    }

    public function testLegacyProfileLandsInShipwreckSeaWithoutLosses(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $before = $this->expedition($client, $profileId);
        $start = $before['world']['position'];
        $target = ['x' => $start['x'] + ($start['x'] < 11 ? 1 : -1), 'y' => $start['y']];
        $this->post($client, "/api/profiles/$profileId/sail", $target);
        self::assertResponseIsSuccessful();
        $sailed = $this->expedition($client, $profileId)['world'];

        // snapshot sprzed akwenów: płaska pozycja, odkrycia i odwiedziny, bez `sea` i `seas`
        $connection = static::getContainer()->get('doctrine')->getConnection();
        $state = json_decode((string) $connection->fetchOne('SELECT state FROM profiles WHERE id = ?', [$profileId]), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('morze-rozbitkow', $state['sea']);
        $legacy = $state['seas']['morze-rozbitkow'];
        unset($state['sea'], $state['seas']);
        $state += $legacy;
        $connection->executeStatement('UPDATE profiles SET state = ? WHERE id = ?', [json_encode($state, JSON_THROW_ON_ERROR), $profileId]);

        $after = $this->expedition($client, $profileId);

        self::assertSame('morze-rozbitkow', $after['world']['sea']['id']);
        self::assertSame($target, $after['world']['position']);
        self::assertEqualsCanonicalizing($sailed['discovered'], $after['world']['discovered']);
        self::assertEqualsCanonicalizing($sailed['visited'], $after['world']['visited']);
        self::assertSame($sailed['tick'], $after['world']['tick']);
        self::assertSame(
            array_column($before['islands'], 'stockpile', 'id'),
            array_column($after['islands'], 'stockpile', 'id'),
        );
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
