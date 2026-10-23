<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

/** Energia w bitwie i „Zakończ turę” przez API (#59). W env testowym AI nie używa broni. */
final class BattleEnergyApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return App\Kernel::class;
    }

    public function testEnergyFlowInFunMode(): void
    {
        $client = static::createClient();
        $id = $this->startGame($client, 'fun');

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame([
            'left' => 3,
            'max' => 3,
            'costs' => ['shot' => 1, 'sonar' => 1, 'torpedo' => 2, 'airRaid' => 3, 'move' => 1],
        ], $view['energy']);

        // sonar: -1⚡, tura zostaje u gracza
        $sonar = $this->json($client, 'POST', "/api/games/$id/sonar", ['x' => 5, 'y' => 5]);
        self::assertSame(2, $sonar['energy']['left']);
        $sonar = $this->json($client, 'POST', "/api/games/$id/sonar", ['x' => 2, 'y' => 7]);
        self::assertSame(1, $sonar['energy']['left']);

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame('player', $view['turn']);
        self::assertSame(1, $view['energy']['left']);

        // torpeda (2⚡) przy 1⚡ — odrzucona, limit użyć nietknięty
        $err = $this->json($client, 'POST', "/api/games/$id/torpedo", ['x' => 0, 'y' => 2, 'direction' => 'E'], 409);
        self::assertSame('NOT_ENOUGH_ENERGY', $err['error']['code']);

        // „Zakończ turę”: AI odpowiada, energia wraca
        $end = $this->json($client, 'POST', "/api/games/$id/end-turn");
        self::assertSame('player', $end['turn']);
        self::assertCount(1, $end['opponentMoves']);
        self::assertSame(3, $end['energy']['left']);

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame(3, $view['energy']['left']);
        self::assertSame(0, $view['shotsCount']);
        self::assertSame(0, $view['weapons']['torpedo']['used']);

        // nalot za całą pulę kończy turę, po ruchu AI pula pełna
        $raid = $this->json($client, 'POST', "/api/games/$id/air-raid", ['x' => 8, 'y' => 2, 'width' => 1, 'height' => 1]);
        self::assertSame('player', $raid['turn']);
        self::assertSame(3, $raid['energy']['left']);
    }

    public function testClassicModeWithoutEnergy(): void
    {
        $client = static::createClient();
        $id = $this->startGame($client, 'classic');

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertNull($view['energy']);

        $err = $this->json($client, 'POST', "/api/games/$id/end-turn", null, 422);
        self::assertSame('END_TURN_NOT_AVAILABLE', $err['error']['code']);

        // salwa jak dotąd: jedna na turę, tura wraca po ruchu AI
        $shot = $this->json($client, 'POST', "/api/games/$id/shots", ['x' => 9, 'y' => 9]);
        self::assertSame('player', $shot['turn']);
        self::assertNull($shot['energy']);
    }

    private function startGame(KernelBrowser $client, string $mode): string
    {
        $id = $this->json($client, 'POST', '/api/games', ['mode' => $mode], 201)['id'];
        $client->request('POST', "/api/games/$id/fleet", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['ships' => FleetFactory::classic10x10Array()], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        return $id;
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client, string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $body ? json_encode($body, JSON_THROW_ON_ERROR) : null);
        self::assertResponseStatusCodeSame($status);

        return json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
