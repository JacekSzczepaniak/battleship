<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Sonar dalekiego zasięgu (Epic G): promień 4 dla gracza w bitwach wyprawy, wróg zostaje przy 3. */
final class LongRangeSonarApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testResearchedSonarScansCrossOfRadiusFourWhileEnemyKeepsThree(): void
    {
        $client = static::createClient();
        [$profileId, $kuterId] = $this->captainAtSignalIsland($client, [Technology::SonarDalekiegoZasiegu]);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [$kuterId]])['id'];
        self::assertResponseStatusCodeSame(201);

        $game = $this->game($client, $gameId);
        self::assertSame(['used' => 0, 'limit' => 1, 'radius' => 4], $game['weapons']['sonar']);
        self::assertSame(['used' => 0, 'limit' => 1, 'radius' => 3], $game['opponentWeapons']['sonar']);

        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2]]]);
        self::assertResponseIsSuccessful();

        // plansza 9×9: krzyż o promieniu 4 ze środka (4,4) sięga wszystkich krawędzi
        $ping = $this->post($client, "/api/games/$gameId/sonar", ['x' => 4, 'y' => 4]);
        self::assertResponseIsSuccessful();
        self::assertSame(4, $ping['radius']);
        self::assertCount(1 + 4 * 4, $ping['results']);
        $cells = array_map(static fn (array $c) => [$c['x'], $c['y']], $ping['results']);
        foreach ([[4, 0], [8, 4], [4, 8], [0, 4]] as $edge) {
            self::assertContains($edge, $cells);
        }
    }

    public function testBattleWithoutTechnologyKeepsRadiusThreeEvenWhenResearchFinishesMidBattle(): void
    {
        $client = static::createClient();
        [$profileId, $kuterId] = $this->captainAtSignalIsland($client);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [$kuterId]])['id'];
        self::assertResponseStatusCodeSame(201);
        self::assertSame(3, $this->game($client, $gameId)['weapons']['sonar']['radius']);

        // badanie kończy się w trakcie bitwy: trwająca bitwa się nie zmienia
        $this->research($profileId, Technology::SonarDalekiegoZasiegu);
        self::assertSame(3, $this->game($client, $gameId)['weapons']['sonar']['radius']);

        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2]]]);
        $this->post($client, "/api/games/$gameId/sonar", ['x' => 4, 'y' => 4, 'radius' => 4]);
        self::assertResponseStatusCodeSame(400);
        $ping = $this->post($client, "/api/games/$gameId/sonar", ['x' => 4, 'y' => 4]);
        self::assertSame(3, $ping['radius']);
        self::assertCount(1 + 4 * 3, $ping['results']);
    }

    public function testTrainingBattleIgnoresTechnologies(): void
    {
        $client = static::createClient();

        $game = $this->post($client, '/api/games', ['mode' => 'fun', 'width' => 10, 'height' => 10]);
        self::assertResponseStatusCodeSame(201);

        $view = $this->game($client, $game['id']);
        self::assertSame(3, $view['weapons']['sonar']['radius']);
        self::assertSame(3, $view['opponentWeapons']['sonar']['radius']);
    }

    /**
     * Kapitan (ranga marynarz) z flotą startową przy Wyspie Sygnałowej (tryb fun, plansza 9×9).
     *
     * @param list<Technology> $researched
     *
     * @return array{0: string, 1: string} id profilu i kutra
     */
    private function captainAtSignalIsland(KernelBrowser $client, array $researched = []): array
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 80, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('wyspa-sygnalowa'),
            researched: $researched,
        ));

        return [$profileId, $profile->fleet()[0]->id];
    }

    /** Dopisuje zbadaną technologię do zapisanego profilu, nie ruszając reszty stanu (bitwy w toku). */
    private function research(string $profileId, Technology $technology): void
    {
        $repository = static::getContainer()->get(ProfileRepository::class);
        $mapper = new ProfileSnapshotMapper();
        $state = $mapper->toArray($repository->get(new ProfileId($profileId)));
        $state['researched'][] = $technology->value;
        $repository->save($mapper->toDomain($profileId, $state));
    }

    /** @return array<string,mixed> */
    private function game(KernelBrowser $client, string $gameId): array
    {
        $client->request('GET', "/api/games/$gameId");
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
