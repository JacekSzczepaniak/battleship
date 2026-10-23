<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Twierdza z fortem (#120): potyczka z flotą oblega wyspę, banderę daje
 * dopiero wygrane oblężenie. Przegraną rozstrzygamy przez repozytorium gry
 * (strzały AI w statki gracza), bo AI strzela losowo.
 */
final class StrongholdApiTest extends WebTestCase
{
    private const FLEET = [
        ['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2],
        ['x' => 0, 'y' => 9, 'o' => 'h', 'l' => 1],
        ['x' => 9, 'y' => 0, 'o' => 'h', 'l' => 1],
        ['x' => 9, 'y' => 9, 'o' => 'h', 'l' => 1],
    ];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testSkirmishBesiegesAndSiegeRaisesTheFlag(): void
    {
        $client = static::createClient();
        [$profileId, $kuterId] = $this->captainAt($client, 'twierdza-admiralicji');

        $island = $this->island($client, $profileId, 'twierdza-admiralicji');
        self::assertTrue($island['stronghold']);
        self::assertFalse($island['besieged']);
        self::assertSame(20, $island['siegeSquadLimit']);

        // przed potyczką oblężenia nie ma
        $err = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/siege");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ISLAND_NOT_BESIEGED', $err['error']['code']);

        // faza 1: potyczka z flotą — wygrana płaci jak porażka i tylko oblega wyspę
        $gameId = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/battle", ['shipIds' => [$kuterId]])['id'];
        self::assertResponseStatusCodeSame(201);
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [self::FLEET[0]]]);
        foreach ([[0, 0], [1, 0]] as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
        }
        $settled = $this->settle($client, $profileId, $gameId);
        self::assertSame('won', $settled['result']);
        self::assertSame('capture', $settled['kind']);
        self::assertFalse($settled['captured']);
        self::assertTrue($settled['besieged']);
        self::assertSame(30, $settled['awarded']); // xpLoss twierdzy
        self::assertSame((new Resources(wood: 15))->toArray(), $settled['loot']);

        $island = $this->island($client, $profileId, 'twierdza-admiralicji');
        self::assertTrue($island['besieged']);
        self::assertFalse($island['owned']);

        // oblężonej wyspy nie atakuje się drugi raz
        $err = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/battle", ['shipIds' => [$kuterId]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ISLAND_ALREADY_BESIEGED', $err['error']['code']);

        // faza 2, próba 1: oblężenie przegrane — statki przepadają, oblężenie zostaje
        $siege = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/siege");
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['w' => 10, 'h' => 10], $siege['board']);
        $this->post($client, "/api/games/{$siege['id']}/fleet", ['ships' => self::FLEET]);
        self::assertResponseIsSuccessful();
        $firstSeed = $this->game($client, $siege['id'])['fort']['seed'];
        $this->sinkPlayerFleet($siege['id']);

        $settled = $this->settle($client, $profileId, $siege['id']);
        self::assertSame('lost', $settled['result']);
        self::assertSame('siege', $settled['kind']);
        self::assertFalse($settled['captured']);
        self::assertFalse($settled['besieged']);
        self::assertSame(30, $settled['awarded']);
        self::assertCount(4, $settled['lostShips']);
        $island = $this->island($client, $profileId, 'twierdza-admiralicji');
        self::assertTrue($island['besieged']);
        self::assertFalse($island['owned']);

        // nowa flota na kolejną próbę
        $this->refit($profileId);

        // faza 2, próba 2: nowy układ fortu, wygrana stawia banderę i płaci pełną stawkę
        $siege = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/siege");
        self::assertResponseStatusCodeSame(201);
        $this->post($client, "/api/games/{$siege['id']}/fleet", ['ships' => self::FLEET]);
        $view = $this->game($client, $siege['id']);
        self::assertNotSame($firstSeed, $view['fort']['seed']);
        self::assertNull($view['boarding']); // fort nie jest statkiem
        $fort = static::getContainer()->get(GameRepository::class)->get(new GameId($siege['id']))->fort();
        foreach ($fort->guns as $gun) {
            $this->post($client, "/api/games/{$siege['id']}/shots", ['x' => $gun->x, 'y' => $gun->y]);
        }
        self::assertSame('won', $this->game($client, $siege['id'])['status']);

        $settled = $this->settle($client, $profileId, $siege['id']);
        self::assertSame('won', $settled['result']);
        self::assertTrue($settled['captured']);
        self::assertFalse($settled['besieged']);
        self::assertSame(120, $settled['awarded']); // xpWin twierdzy
        self::assertSame((new Resources(wood: 20, steel: 22, gold: 18))->toArray(), $settled['loot']);

        $expedition = $this->expedition($client, $profileId);
        $island = array_column($expedition['islands'], null, 'id')['twierdza-admiralicji'];
        self::assertTrue($island['owned']);
        self::assertFalse($island['besieged']);
        self::assertSame(['wins' => 2, 'losses' => 1], ['wins' => $island['wins'], 'losses' => $island['losses']]);
        $events = array_column($expedition['log'], 'event');
        foreach (['island-besieged', 'siege-failed', 'island-captured'] as $event) {
            self::assertContains($event, $events);
        }
        $besieged = array_values(array_filter($expedition['log'], static fn ($e) => 'island-besieged' === $e['event']))[0];
        self::assertSame('Twierdza Admiralicji', $besieged['islandName']);
    }

    public function testSiegeValidation(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captainAt($client, 'mielizny');

        $island = $this->island($client, $profileId, 'mielizny');
        self::assertFalse($island['stronghold']);
        self::assertNull($island['siegeSquadLimit']);

        $err = $this->post($client, "/api/profiles/$profileId/islands/mielizny/siege");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ISLAND_HAS_NO_FORT', $err['error']['code']);

        // flota nie stoi przy twierdzy
        $err = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/siege");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_ISLAND', $err['error']['code']);

        $err = $this->post($client, "/api/profiles/$profileId/islands/nie-ma-takiej/siege");
        self::assertResponseStatusCodeSame(404);
        self::assertSame('ISLAND_NOT_FOUND', $err['error']['code']);
    }

    /**
     * Kapitan z rangą kapitana i flotą startową, zakotwiczony przy wyspie.
     *
     * @return array{string, string} id profilu i id kutra
     */
    private function captainAt(KernelBrowser $client, string $islandId): array
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 600, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition($islandId),
        ));

        return [$profileId, $profile->fleet()[0]->id];
    }

    /** Flota startowa w miejsce straconej, z zachowaniem reszty profilu. */
    private function refit(string $profileId): void
    {
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $fresh = CaptainProfile::create('x')->fleet();
        $mapper = new ProfileSnapshotMapper();
        $state = $mapper->toArray($profile);
        $state['fleet'] = array_map(static fn ($s) => ['id' => $s->id, 'type' => $s->type->value, 'damaged' => false], $fresh);
        $repository->save($mapper->toDomain($profileId, $state));
    }

    private function sinkPlayerFleet(string $gameId): void
    {
        $games = static::getContainer()->get(GameRepository::class);
        $game = $games->get(new GameId($gameId));
        foreach ($game->fleet() as $ship) {
            foreach ($ship->cells() as $cell) {
                $game->fireOpponentShot(new Coordinate($cell->x, $cell->y));
            }
        }
        $games->save($game);
    }

    /** @return array<string,mixed> */
    private function settle(KernelBrowser $client, string $profileId, string $gameId): array
    {
        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful((string) $client->getResponse()->getContent());

        return $settled;
    }

    /** @return array<string,mixed> */
    private function island(KernelBrowser $client, string $profileId, string $islandId): array
    {
        return array_column($this->expedition($client, $profileId)['islands'], null, 'id')[$islandId];
    }

    /** @return array<string,mixed> */
    private function game(KernelBrowser $client, string $gameId): array
    {
        $client->request('GET', "/api/games/$gameId");
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function expedition(KernelBrowser $client, string $profileId): array
    {
        $client->request('GET', "/api/profiles/$profileId/expedition");
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function post(KernelBrowser $client, string $url, array $payload = []): array
    {
        $client->request(
            'POST',
            $url,
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode($payload, JSON_THROW_ON_ERROR),
        );

        return json_decode($client->getResponse()->getContent() ?: '{}', true) ?? [];
    }
}
