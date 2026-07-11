<?php

declare(strict_types=1);

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

final class GameApiFunModeTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return App\Kernel::class;
    }

    public function testFunModeFullFlow(): void
    {
        $client = static::createClient();

        // create fun game
        $client->request('POST', '/api/games', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['mode' => 'fun']));
        self::assertResponseStatusCodeSame(201);
        $created = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fun', $created['ruleset']);
        $id = $created['id'];

        // place fleet
        $client->request('POST', "/api/games/$id/fleet", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['ships' => FleetFactory::classic10x10Array()], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // GET exposes ruleset and weapons state
        $client->request('GET', "/api/games/$id");
        self::assertResponseIsSuccessful();
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame('fun', $view['ruleset']);
        self::assertSame(['used' => 0, 'limit' => 2], $view['weapons']['torpedo']);
        self::assertSame(['used' => 0, 'limit' => 3], $view['weapons']['sonar']);
        self::assertSame(['used' => 0, 'limit' => 1], $view['weapons']['airRaid']);

        // torpedo: full turn — results + AI response + turn back to player
        $client->request('POST', "/api/games/$id/torpedo", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['x' => 0, 'y' => 5, 'direction' => 'E']));
        self::assertResponseIsSuccessful();
        $torpedo = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(10, $torpedo['results']);
        self::assertCount(1, $torpedo['opponentMoves']);
        self::assertSame('player', $torpedo['turn']);
        self::assertFalse($torpedo['finished']);

        // weapon use persisted (survives repository round-trip, incl. fun ruleset)
        $client->request('GET', "/api/games/$id");
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['used' => 1, 'limit' => 2], $view['weapons']['torpedo']);
        self::assertSame('fun', $view['ruleset']);

        // air raid endpoint (route regression: '/api/games/{id}/air-raid' z wiodącym slashem)
        $client->request('POST', "/api/games/$id/air-raid", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['x' => 8, 'y' => 2, 'width' => 1, 'height' => 1]));
        self::assertResponseIsSuccessful();
        $raid = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(9, $raid['results']);
        self::assertCount(1, $raid['opponentMoves']);

        // sonar endpoint — nie zużywa tury, ale zużywa limit
        $client->request('POST', "/api/games/$id/sonar", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['x' => 5, 'y' => 5]));
        self::assertResponseIsSuccessful();

        $client->request('GET', "/api/games/$id");
        $view = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['used' => 1, 'limit' => 1], $view['weapons']['airRaid']);
        self::assertSame(['used' => 1, 'limit' => 3], $view['weapons']['sonar']);
    }

    public function testWeaponsRejectedInClassicGame(): void
    {
        $client = static::createClient();

        $client->request('POST', '/api/games', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode([]));
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        $client->request('POST', "/api/games/$id/fleet", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['ships' => FleetFactory::classic10x10Array()], JSON_THROW_ON_ERROR));
        self::assertResponseIsSuccessful();

        // classic → torpeda niedostępna (422 z ExceptionSubscriber)
        $client->request('POST', "/api/games/$id/torpedo", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['x' => 0, 'y' => 5, 'direction' => 'E']));
        self::assertResponseStatusCodeSame(422);

        // classic → sonar niedostępny
        $client->request('POST', "/api/games/$id/sonar", server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['x' => 5, 'y' => 5]));
        self::assertResponseStatusCodeSame(422);
    }

    public function testInvalidModeRejected(): void
    {
        $client = static::createClient();
        $client->request('POST', '/api/games', server: ['CONTENT_TYPE' => 'application/json'], content: json_encode(['mode' => 'hardcore']));
        self::assertResponseStatusCodeSame(400);
    }
}
