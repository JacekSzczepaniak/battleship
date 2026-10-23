<?php

declare(strict_types=1);

use App\Domain\Game\Siege\FortGenerator;
use App\Domain\Game\Siege\FortPiece;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

/**
 * Bitwa oblężnicza przez API (#119, trening): fort z seeda zamiast floty AI.
 * Układ fortu znamy z FortGenerator, więc strzały gracza są deterministyczne;
 * strzały fortu (AI) w testach są losowe, ale 7 salw nie zatopi całej floty.
 */
final class SiegeApiTest extends WebTestCase
{
    private const SEED = 5;

    protected static function getKernelClass(): string
    {
        return App\Kernel::class;
    }

    public function testSiegeBattleFlow(): void
    {
        $client = static::createClient();
        $fort = (new FortGenerator())->generate(self::SEED);

        $created = $this->json($client, 'POST', '/api/games', ['mode' => 'classic', 'width' => 14, 'height' => 14, 'siege' => true, 'seed' => self::SEED], 201);
        self::assertSame('fun', $created['ruleset']);
        self::assertSame(['w' => 10, 'h' => 10], $created['board']);
        self::assertSame(self::SEED, $created['fortSeed']);
        $id = $created['id'];

        $client->request('POST', "/api/games/$id/fleet", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['ships' => FleetFactory::classic10x10Array()], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame(['left' => 4, 'total' => 4], $view['fort']['guns']);
        self::assertSame([], $view['fort']['revealed']);
        self::assertNull($view['fort']['explosion']);
        self::assertNotNull($view['energy'], 'gracz ma energię');

        // sonar widzi mur jak statek
        $wall = $fort->walls[0]->start;
        $ping = $this->json($client, 'POST', "/api/games/$id/sonar", ['x' => $wall->x, 'y' => $wall->y, 'radius' => 0]);
        self::assertSame([['x' => $wall->x, 'y' => $wall->y, 'occupied' => true]], $ping['results']);

        // mur: trafiony, odsłonięty, bez wygranej
        $shot = $this->json($client, 'POST', "/api/games/$id/shots", ['x' => $wall->x, 'y' => $wall->y]);
        self::assertSame('hit', $shot['result']);
        self::assertFalse($shot['win']);
        self::assertCount(1, $shot['opponentMoves'], 'fort oddaje jeden strzał');
        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertContains(['x' => $wall->x, 'y' => $wall->y, 'kind' => 'wall'], $view['fort']['revealed']);
        self::assertSame(4, $view['fort']['guns']['left']);

        // magazyn: eksplozja 3×3 (przycięta do planszy), pola trafione jako strzały
        $mag = $fort->magazine;
        $shot = $this->json($client, 'POST', "/api/games/$id/shots", ['x' => $mag->x, 'y' => $mag->y]);
        self::assertSame('sunk', $shot['result']);
        $view = $this->json($client, 'GET', "/api/games/$id");
        $blast = array_map(static fn ($c) => [$c->x, $c->y], $fort->blastCells(10, 10));
        self::assertSame($blast, $view['fort']['explosion']);
        $shotCells = [...$view['enemyFogGrid']['hits'], ...$view['enemyFogGrid']['misses']];
        foreach ($blast as $cell) {
            self::assertContains($cell, $shotCells);
        }
        self::assertContains(['x' => $mag->x, 'y' => $mag->y, 'kind' => 'magazine'], $view['fort']['revealed']);

        // pozostałe działa: ostatnie daje wygraną, fort milknie
        $left = array_values(array_filter($fort->guns, static fn ($g) => !in_array([$g->x, $g->y], $view['enemyFogGrid']['hits'], true)));
        self::assertSame(count($left), $view['fort']['guns']['left']);
        foreach ($left as $i => $gun) {
            $shot = $this->json($client, 'POST', "/api/games/$id/shots", ['x' => $gun->x, 'y' => $gun->y]);
            self::assertSame('sunk', $shot['result']);
            $last = $i === count($left) - 1;
            self::assertSame($last, $shot['win']);
            self::assertCount($last ? 0 : 1, $shot['opponentMoves']);
        }

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertTrue($view['finished']);
        self::assertSame('won', $view['status']);
        self::assertSame(0, $view['fort']['guns']['left']);
        foreach ($view['fort']['revealed'] as $cell) {
            self::assertSame($fort->pieceAt($cell['x'], $cell['y'])?->value, $cell['kind']);
        }
        self::assertContains(FortPiece::Gun->value, array_column($view['fort']['revealed'], 'kind'));
    }

    public function testValidationAndPlainGame(): void
    {
        $client = static::createClient();
        foreach ([-1, 'abc', 1.5] as $seed) {
            $err = $this->json($client, 'POST', '/api/games', ['mode' => 'fun', 'siege' => true, 'seed' => $seed], 400);
            self::assertSame('VALIDATION_ERROR', $err['error']['code']);
        }

        // bez seeda: losowy, ale zapisany w grze
        $created = $this->json($client, 'POST', '/api/games', ['mode' => 'fun', 'siege' => true], 201);
        self::assertIsInt($created['fortSeed']);
        $view = $this->json($client, 'GET', '/api/games/'.$created['id']);
        self::assertSame($created['fortSeed'], $view['fort']['seed']);

        $plain = $this->json($client, 'POST', '/api/games', ['mode' => 'fun'], 201);
        self::assertNull($plain['fortSeed']);
        self::assertNull($this->json($client, 'GET', '/api/games/'.$plain['id'])['fort']);
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client, string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $body ? json_encode($body, JSON_THROW_ON_ERROR) : null);
        self::assertResponseStatusCodeSame($status, (string) $client->getResponse()->getContent());

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
