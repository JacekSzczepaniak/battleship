<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class GameApiSunkProjectionTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return App\Kernel::class;
    }

    /**
     * Regresja: enemyFogGrid.sunk było liczone względem floty GRACZA zamiast
     * przeciwnika — niewidoczne w testach z identycznymi flotami, więc flota
     * gracza jest tu celowo INNA niż deterministyczna flota przeciwnika.
     */
    public function testSunkShipsComputedAgainstOpponentFleet(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/games', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([]));
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // Alternatywny, poprawny układ klasycznej floty (bez styku), różny od FleetFactory
        $altFleet = [
            ['x' => 0, 'y' => 9, 'o' => 'H', 'l' => 4],
            ['x' => 5, 'y' => 9, 'o' => 'H', 'l' => 3],
            ['x' => 9, 'y' => 0, 'o' => 'V', 'l' => 3],
            ['x' => 0, 'y' => 0, 'o' => 'V', 'l' => 2],
            ['x' => 2, 'y' => 1, 'o' => 'H', 'l' => 2],
            ['x' => 5, 'y' => 3, 'o' => 'V', 'l' => 2],
            ['x' => 7, 'y' => 1, 'o' => 'H', 'l' => 1],
            ['x' => 0, 'y' => 4, 'o' => 'H', 'l' => 1],
            ['x' => 3, 'y' => 6, 'o' => 'H', 'l' => 1],
            ['x' => 7, 'y' => 6, 'o' => 'H', 'l' => 1],
        ];
        $client->request('POST', "/api/games/$id/fleet", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['ships' => $altFleet], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // Flota przeciwnika (deterministyczna w env testowym) ma jedynkę na (0,6);
        // gracz na (0,6) nie ma nic — strzał zatapia statek przeciwnika.
        $client->request('POST', "/api/games/$id/shots", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['x' => 0, 'y' => 6]));
        self::assertResponseIsSuccessful();
        $shot = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('sunk', $shot['result']);

        // Projekcja musi raportować zatopioną jedynkę przeciwnika na (0,6)
        $client->request('GET', "/api/games/$id");
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame([['cells' => [[0, 6]]]], $view['enemyFogGrid']['sunk']);
    }
}
