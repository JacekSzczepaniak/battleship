<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameApiCustomFleetTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testGameWithCustomFleetComposition(): void
    {
        $client = static::createClient();

        // 1) gra z niestandardowym składem: 1 dwumasztowiec + 2 tratwy
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['mode' => 'classic', 'ships' => [2 => 1, 1 => 2]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // 2) GET zwraca skład do wystawienia (klucze JSON jako stringi)
        $client->request('GET', "/api/games/$id");
        self::assertResponseIsSuccessful();
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['2' => 1, '1' => 2], $view['allowedShips']);

        // 3) flota niezgodna ze składem zostaje odrzucona…
        $client->request(
            'POST',
            "/api/games/$id/fleet",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ships' => [
                ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 4],
            ]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(422);

        // 4) …a zamówiony skład przechodzi (przeciwnik dostaje ten sam skład
        //    z deterministycznego generatora w env testowym)
        $client->request(
            'POST',
            "/api/games/$id/fleet",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ships' => [
                ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 2],
                ['x' => 4, 'y' => 0, 'o' => 'h', 'l' => 1],
                ['x' => 6, 'y' => 2, 'o' => 'h', 'l' => 1],
            ]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();
    }

    public function testRejectsInvalidCompositionPayload(): void
    {
        $client = static::createClient();

        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ships' => [9 => 1]], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(400);
    }
}
