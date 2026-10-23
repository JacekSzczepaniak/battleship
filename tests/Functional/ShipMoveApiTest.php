<?php

declare(strict_types=1);

use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Game\ShotResult;
use App\Domain\Shared\GameId;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

/**
 * Ruch statku przez API (#60). Strzały wroga ustawiamy przez repozytorium,
 * bo AI w testach strzela losowo. Flota: FleetFactory::classic10x10().
 */
final class ShipMoveApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return App\Kernel::class;
    }

    public function testMoveRulesAndFlow(): void
    {
        $client = static::createClient();
        $id = $this->startGame($client, 'fun');

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame(1, $view['energy']['costs']['move']);
        self::assertFalse($view['moveUsed']);

        // wróg trafił czteromasztowiec w (0,0) i spudłował w (8,7) nad tratwą (8,8)
        $this->enemyFires($id, [[0, 0, ShotResult::Hit], [8, 7, ShotResult::Miss]]);

        $rejected = [
            'trafiony statek' => [['x' => 2, 'y' => 0, 'direction' => 'E'], 'SHIP_MOVE_DAMAGED'],
            'pole ostrzelane' => [['x' => 8, 'y' => 8, 'direction' => 'N'], 'SHIP_MOVE_SHOT_CELL'],
            'poza planszę' => [['x' => 5, 'y' => 9, 'direction' => 'S'], 'SHIP_MOVE_OUT_OF_BOUNDS'],
            'styk z tratwą (0,6)' => [['x' => 1, 'y' => 8, 'direction' => 'N'], 'SHIP_MOVE_TOUCH'],
            'w poprzek osi' => [['x' => 0, 'y' => 2, 'direction' => 'N'], 'SHIP_MOVE_DIRECTION'],
            'puste pole' => [['x' => 4, 'y' => 4, 'direction' => 'N'], 'SHIP_MOVE_NO_SHIP'],
        ];
        foreach ($rejected as $case => [$body, $code]) {
            $err = $this->json($client, 'POST', "/api/games/$id/move", $body, 422);
            self::assertSame($code, $err['error']['code'], $case);
        }

        $move = $this->json($client, 'POST', "/api/games/$id/move", ['x' => 8, 'y' => 8, 'direction' => 'W']);
        self::assertSame(['x' => 7, 'y' => 8, 'o' => 'H', 'l' => 1], $move['ship']);
        self::assertTrue($move['moveUsed']);
        // odrzucone ruchy nie zużyły energii
        self::assertSame(2, $move['energy']['left']);

        $err = $this->json($client, 'POST', "/api/games/$id/move", ['x' => 0, 'y' => 2, 'direction' => 'E'], 409);
        self::assertSame('SHIP_ALREADY_MOVED', $err['error']['code']);

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame('player', $view['turn']);
        self::assertTrue($view['moveUsed']);
        self::assertContains(['x' => 7, 'y' => 8, 'o' => 'H', 'l' => 1], $view['playerFleet']);
        self::assertNotContains(['x' => 8, 'y' => 8, 'o' => 'H', 'l' => 1], $view['playerFleet']);

        // wróg trafia tratwę na nowym polu, stare to już woda
        $this->enemyFires($id, [[8, 8, ShotResult::Miss], [7, 8, ShotResult::Sunk]]);
        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertContains([7, 8], $view['playerUnderFireGrid']['hits']);
        self::assertContains([8, 8], $view['playerUnderFireGrid']['misses']);

        // po ruchu wroga ruch wraca
        $this->json($client, 'POST', "/api/games/$id/end-turn");
        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertFalse($view['moveUsed']);
    }

    public function testInvalidPayloadAndClassicMode(): void
    {
        $client = static::createClient();
        $id = $this->startGame($client, 'fun');

        foreach ([['x' => 8, 'y' => 8, 'direction' => 'NE'], ['x' => 8, 'y' => 8], ['x' => -1, 'y' => 8, 'direction' => 'N']] as $body) {
            $err = $this->json($client, 'POST', "/api/games/$id/move", $body, 400);
            self::assertSame('VALIDATION_ERROR', $err['error']['code']);
        }

        $classic = $this->startGame($client, 'classic');
        self::assertFalse($this->json($client, 'GET', "/api/games/$classic")['moveUsed']);
        $err = $this->json($client, 'POST', "/api/games/$classic/move", ['x' => 8, 'y' => 8, 'direction' => 'W'], 422);
        self::assertSame('SHIP_MOVE_NOT_AVAILABLE', $err['error']['code']);
    }

    /** @param list<array{0:int,1:int,2:ShotResult}> $shots strzały wroga z oczekiwanym wynikiem */
    private function enemyFires(string $id, array $shots): void
    {
        $repo = static::getContainer()->get(GameRepository::class);
        $game = $repo->get(new GameId($id));
        foreach ($shots as [$x, $y, $expected]) {
            self::assertSame($expected, $game->fireOpponentShot(new Coordinate($x, $y)));
        }
        $repo->save($game);
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

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
