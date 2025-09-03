<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

final class GameApiListShotsTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testListShotsAfterMissAndHit(): void
    {
        $client = static::createClient();

        // 1) create
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 12, 'height' => 10])
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true)['id'];

        // 2) place fleet
        $fleet = FleetFactory::classic10x10Array();
        $client->request(
            'POST',
            "/api/games/$id/fleet",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ships' => $fleet], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        // 3) MISS
        $client->request(
            'POST',
            "/api/games/$id/shots",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 4, 'y' => 4], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        // 4) HIT (wiadomo, że (0,0) należy do statku)
        $client->request(
            'POST',
            "/api/games/$id/shots",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 0, 'y' => 0], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        // 5) GET list of shots
        $client->request('GET', "/api/games/$id/shots");
        self::assertResponseIsSuccessful();

        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // finished powinno być false po 2 strzałach
        self::assertArrayHasKey('finished', $payload);
        self::assertFalse($payload['finished']);

        // sprawdź strukturę i wyniki
        self::assertArrayHasKey('shots', $payload);
        self::assertIsArray($payload['shots']);

        // przemapuj do zbioru "x:y" => result
        $byKey = [];
        foreach ($payload['shots'] as $s) {
            $byKey[$s['x'].':'.$s['y']] = $s['result'];
        }

        self::assertSame('miss', $byKey['4:4'] ?? null);
        self::assertSame('hit', $byKey['0:0'] ?? null);
    }

    public function testListShotsNotFound(): void
    {
        $client = static::createClient();
        $client->request('GET', '/api/games/non-existent-id/shots');
        self::assertResponseStatusCodeSame(404);
    }
}
