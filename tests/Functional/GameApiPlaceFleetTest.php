<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameApiPlaceFleetTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return App\Kernel::class;
    }

    public function testCreateThenPlaceFleet(): void
    {
        $client = static::createClient();

        // create game
        $client->request('POST', '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 12, 'height' => 10])
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true)['id'];

        // place fleet (z poprawionymi koordynatami: (5,7) zamiast (4,7))
        $payload = [
            'ships' => [
                ['x' => 0, 'y' => 0, 'o' => 'H', 'l' => 4],
                ['x' => 0, 'y' => 2, 'o' => 'H', 'l' => 3],
                ['x' => 5, 'y' => 2, 'o' => 'V', 'l' => 3],
                ['x' => 8, 'y' => 0, 'o' => 'V', 'l' => 2],
                ['x' => 10, 'y' => 5, 'o' => 'H', 'l' => 2],
                ['x' => 2, 'y' => 7, 'o' => 'H', 'l' => 2],
                ['x' => 0, 'y' => 5, 'o' => 'H', 'l' => 1],
                ['x' => 5, 'y' => 7, 'o' => 'H', 'l' => 1],
                ['x' => 7, 'y' => 7, 'o' => 'H', 'l' => 1],
                ['x' => 11, 'y' => 9, 'o' => 'H', 'l' => 1],
            ],
        ];

        $client->request('POST', "/api/games/$id/fleet",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload)
        );
        self::assertResponseIsSuccessful();

        // get game — status powinien być co najmniej „in progress” (jeśli tak ustawiasz po flocie)
        $client->request('GET', "/api/games/$id");
        self::assertResponseIsSuccessful();
    }
}
