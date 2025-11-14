<?php


declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameApiTorpedoValidationTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testTorpedoInvalidPayload(): void
    {
        $client = static::createClient();

        // create
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 10, 'height' => 10], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // brak wymaganych pól
        $client->request(
            'POST',
            "/api/games/$id/torpedo",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['foo' => 'bar'], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(400);

        // niepoprawny kierunek
        $client->request(
            'POST',
            "/api/games/$id/torpedo",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 0, 'y' => 0, 'direction' => 'Q'], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testTorpedoBeforeFleetPlaced(): void
    {
        $client = static::createClient();

        // create (bez rozstawiania floty)
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 10, 'height' => 10], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // torpeda -> domena rzuci DomainException('Fleet not placed') -> 422
        $client->request(
            'POST',
            "/api/games/$id/torpedo",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 0, 'y' => 0, 'direction' => 'E'], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(422);
    }
}
