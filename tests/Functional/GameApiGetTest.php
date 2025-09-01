<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * @group functional
 */
final class GameApiGetTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return App\Kernel::class;
    }

    public function testCreateThenGet(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 12, 'height' => 10])
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true)['id'];

        $client->request('GET', "/api/games/$id");
        self::assertResponseIsSuccessful();
        $data = json_decode($client->getResponse()->getContent(), true);
        self::assertSame($id, $data['id']);
        self::assertSame(['w' => 12, 'h' => 10], $data['board']);
        self::assertSame('pending', $data['status']);
    }
}
