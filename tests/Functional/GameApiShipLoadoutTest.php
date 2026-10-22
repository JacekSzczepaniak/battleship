<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Start bitwy z niestandardowym wyposażeniem (#95): jeden z dwóch niszczycieli
 * niesie 2 sonary zamiast torpedy, gracz wystawia go z polem `w`.
 */
final class GameApiShipLoadoutTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testBattleWithScoutVariant(): void
    {
        $client = static::createClient();
        $id = $this->createScoutGame($client);

        // stan broni ma dotychczasowy kształt, limity liczą się z wyposażenia
        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame(['used' => 0, 'limit' => 1], $view['weapons']['torpedo']);
        self::assertSame(['used' => 0, 'limit' => 2], $view['weapons']['sonar']);
        self::assertSame(['used' => 0, 'limit' => 0], $view['weapons']['airRaid']);
        self::assertSame($view['weapons'], $view['opponentWeapons']);

        // flota bez wyposażenia zwiadowcy nie pasuje do składu
        $this->json($client, 'POST', "/api/games/$id/fleet", ['ships' => $this->fleet(scout: false)]);
        self::assertResponseStatusCodeSame(422);

        $this->json($client, 'POST', "/api/games/$id/fleet", ['ships' => $this->fleet(scout: true)]);
        self::assertResponseIsSuccessful();

        // GET oddaje wyposażenie zwiadowcy, zwykłe statki bez `w`
        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame(['sonar' => 2], $view['playerFleet'][0]['w']);
        self::assertArrayNotHasKey('w', $view['playerFleet'][1]);

        // sonar działa ze zwiadowcy (nie kończy tury)
        $this->json($client, 'POST', "/api/games/$id/sonar", ['x' => 5, 'y' => 5, 'radius' => 3]);
        self::assertResponseIsSuccessful();

        // torpeda nie startuje ze zwiadowcy…
        $error = $this->json($client, 'POST', "/api/games/$id/torpedo", ['x' => 0, 'y' => 0, 'direction' => 'S']);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('TORPEDO_LAUNCH_INVALID', $error['error']['code']);

        // …tylko ze zwykłego niszczyciela
        $this->json($client, 'POST', "/api/games/$id/torpedo", ['x' => 0, 'y' => 2, 'direction' => 'E']);
        self::assertResponseIsSuccessful();

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame(['used' => 1, 'limit' => 2], $view['weapons']['sonar']);
        self::assertSame(['used' => 1, 'limit' => 1], $view['weapons']['torpedo']);
    }

    public function testRejectsInvalidVariants(): void
    {
        $client = static::createClient();

        $this->json($client, 'POST', '/api/games', ['mode' => 'fun', 'ships' => [3 => 1], 'variants' => [3 => [['laser' => 1]]]]);
        self::assertResponseStatusCodeSame(400);

        $this->json($client, 'POST', '/api/games', ['mode' => 'fun', 'ships' => [3 => 1], 'variants' => [2 => [['sonar' => 1]]]]);
        self::assertResponseStatusCodeSame(400);
    }

    private function createScoutGame(KernelBrowser $client): string
    {
        $created = $this->json($client, 'POST', '/api/games', [
            'mode' => 'fun',
            'ships' => [3 => 2, 1 => 2],
            'variants' => [3 => [['sonar' => 2]]],
        ]);
        self::assertResponseStatusCodeSame(201);

        return $created['id'];
    }

    /** @return list<array<string,mixed>> */
    private function fleet(bool $scout): array
    {
        $first = ['x' => 0, 'y' => 0, 'o' => 'H', 'l' => 3];
        if ($scout) {
            $first['w'] = ['sonar' => 2];
        }

        return [
            $first,
            ['x' => 0, 'y' => 2, 'o' => 'H', 'l' => 3],
            ['x' => 5, 'y' => 5, 'o' => 'H', 'l' => 1],
            ['x' => 7, 'y' => 7, 'o' => 'H', 'l' => 1],
        ];
    }

    /**
     * @param array<string,mixed>|null $body
     *
     * @return array<string,mixed>
     */
    private function json(KernelBrowser $client, string $method, string $uri, ?array $body = null): array
    {
        $client->request(
            $method,
            $uri,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: null === $body ? null : json_encode($body, JSON_THROW_ON_ERROR),
        );

        return json_decode($client->getResponse()->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
