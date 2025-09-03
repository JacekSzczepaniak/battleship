<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

final class GameApiFireShotTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return App\Kernel::class;
    }

    public function testFullFlowFireMissAndHit(): void
    {
        $client = static::createClient();

        // create
        $client->request('POST', '/api/games', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['width' => 12, 'height' => 10]));
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true)['id'];

        // place fleet
        $fleet = FleetFactory::classic10x10Array();

        $client->request(
            'POST',
            "/api/games/$id/fleet",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ships' => $fleet],
                JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        // MISS (pole wody)
        $client->request(
            'POST',
            "/api/games/$id/shots",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['x' => 4, 'y' => 4], JSON_THROW_ON_ERROR)
        );

        self::assertResponseIsSuccessful();

        $miss = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('miss', $miss['result']);
        self::assertFalse($miss['win']);

        // HIT (wiemy, że (0,0) jest częścią czteromasztowca)
        $client->request('POST', "/api/games/$id/shots", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['x' => 0, 'y' => 0]));
        self::assertResponseIsSuccessful();
        $hit = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('hit', $hit['result']);
    }
}
