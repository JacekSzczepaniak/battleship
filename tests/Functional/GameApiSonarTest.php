<?php


declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

final class GameApiSonarTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testSonarReturnsCrossShapeAndOccupancy(): void
    {
        $client = static::createClient();

        // 1) create 10x10
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 10, 'height' => 10], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // 2) place classic fleet
        $fleet = FleetFactory::classic10x10Array();
        $client->request(
            'POST',
            "/api/games/$id/fleet",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ships' => $fleet], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        // 3) sonar from (0,0) with radius 3 (cross)
        $client->request(
            'POST',
            "/api/games/$id/sonar",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 0, 'y' => 0, 'radius' => 3], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertArrayHasKey('results', $payload);
        self::assertSame('cross', $payload['shape'] ?? null);
        self::assertSame(3, $payload['radius'] ?? null);

        $results = $payload['results'];
        self::assertIsArray($results);

        // Map results to "x:y" => occupied
        $byKey = [];
        foreach ($results as $r) {
            $byKey[$r['x'] . ':' . $r['y']] = (bool)$r['occupied'];
        }

        // Expected scanned coordinates within bounds for radius=3 from (0,0)
        $expectedKeys = [
            '0:0', '1:0', '2:0', '3:0', // east
            '0:1', '0:2', '0:3',       // south
            // north and west go out of bounds from (0,0) -> not present
        ];
        foreach ($expectedKeys as $k) {
            self::assertArrayHasKey($k, $byKey, "Expected scanned cell missing: $k");
        }

        // Occupancy checks based on known FleetFactory layout:
        // 4-length horizontal ship at (0,0) covers (0,0),(1,0),(2,0),(3,0)
        self::assertTrue($byKey['0:0']);
        self::assertTrue($byKey['1:0']);
        self::assertTrue($byKey['2:0']);
        self::assertTrue($byKey['3:0']);

        // Cells on the same column but away from ships (within radius) should be false
        // On the south ray: (0,2) is part of a 3-length horizontal ship starting at (0,2)
        self::assertFalse($byKey['0:1']);
        self::assertTrue($byKey['0:2']);
        self::assertFalse($byKey['0:3']);
    }

    public function testSonarDetectsSingleCellShipWithRadiusZero(): void
    {
        $client = static::createClient();

        // create 10x10
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 10, 'height' => 10], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // place classic fleet
        $fleet = \Tests\Support\FleetFactory::classic10x10Array();
        $client->request(
            'POST',
            "/api/games/$id/fleet",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ships' => $fleet], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        // radius=0 should only scan the center cell
        $client->request(
            'POST',
            "/api/games/$id/sonar",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 0, 'y' => 6, 'radius' => 0], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        $results = $payload['results'] ?? [];
        self::assertCount(1, $results);
        self::assertSame(0, $results[0]['x']);
        self::assertSame(6, $results[0]['y']);
        self::assertTrue((bool)$results[0]['occupied']); // single ship at (0,6)
    }
}
