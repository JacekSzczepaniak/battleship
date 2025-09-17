<?php


declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameApiSonarValidationTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testSonarInvalidPayload(): void
    {
        $client = static::createClient();

        // create a game
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 10, 'height' => 10], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // missing required fields -> 400
        $client->request(
            'POST',
            "/api/games/$id/sonar",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(400);

        // invalid radius (negative) -> 400
        $client->request(
            'POST',
            "/api/games/$id/sonar",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 0, 'y' => 0, 'radius' => -1], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testSonarBeforeFleetPlaced(): void
    {
        $client = static::createClient();

        // create (without placing a fleet)
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 10, 'height' => 10], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // sonar -> domain throws DomainException('Fleet not placed') -> 422
        $client->request(
            'POST',
            "/api/games/$id/sonar",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 0, 'y' => 0, 'radius' => 3], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(422);
    }
}
