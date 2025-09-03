<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameApiFireShotValidationTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFireShotInvalidPayload(): void
    {
        $client = static::createClient();

        // create
        $client->request('POST', '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 10, 'height' => 10])
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true)['id'];

        // brak kluczy x/y -> 400
        $client->request(
            'POST',
            "/api/games/$id/shots",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['foo' => 1], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(400);
    }

    public function testFireShotBeforeFleetPlaced(): void
    {
        $client = static::createClient();

        // create (bez rozstawiania floty)
        $client->request('POST', '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 10, 'height' => 10])
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true)['id'];

        // strzał -> domena rzuci DomainException('Fleet not placed') -> 422
        $client->request(
            'POST',
            "/api/games/$id/shots",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 0, 'y' => 0], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(422);
    }
}
