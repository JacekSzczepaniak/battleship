<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ResearchApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testAcademyStartsOneResearchAtATime(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAt($client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka']);

        $research = $this->expedition($client, $profileId)['research'];
        self::assertNull($research['current']);
        self::assertSame(['kadluby', 'uzbrojenie', 'nawigacja', 'osady'], $research['branches']);
        self::assertSame([
            ['level' => 1, 'cost' => ['wood' => 20, 'steel' => 20, 'gold' => 5], 'ticks' => 6, 'requiredRank' => null],
            ['level' => 2, 'cost' => ['wood' => 40, 'steel' => 30, 'gold' => 15], 'ticks' => 10, 'requiredRank' => 'sternik'],
            ['level' => 3, 'cost' => ['wood' => 60, 'steel' => 50, 'gold' => 30], 'ticks' => 14, 'requiredRank' => 'kapitan'],
        ], $research['levels']);
        self::assertSame([
            ['id' => 'spichlerze', 'branch' => 'osady', 'level' => 1, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'no-academy'],
            ['id' => 'mapy-sztormowe', 'branch' => 'nawigacja', 'level' => 1, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'no-academy'],
            ['id' => 'sonar-dalekiego-zasiegu', 'branch' => 'uzbrojenie', 'level' => 1, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'no-academy'],
            ['id' => 'zegluga-dwusektorowa', 'branch' => 'nawigacja', 'level' => 2, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'prerequisite'],
            ['id' => 'pojemne-ladownie', 'branch' => 'kadluby', 'level' => 1, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'no-academy'],
            ['id' => 'dok-remontowy', 'branch' => 'kadluby', 'level' => 2, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'prerequisite'],
            ['id' => 'lepsze-narzedzia', 'branch' => 'osady', 'level' => 2, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'prerequisite'],
            ['id' => 'niszczyciel-zwiadowczy', 'branch' => 'uzbrojenie', 'level' => 2, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'prerequisite'],
            ['id' => 'nawigacja-oceaniczna', 'branch' => 'nawigacja', 'level' => 3, 'state' => 'locked', 'ticksLeft' => null, 'blocker' => 'prerequisite'],
        ], $research['technologies']);

        // bez uczelni badania nie da się zacząć
        $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/research", ['technology' => 'spichlerze']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_ACADEMY', $this->lastErrorCode($client));

        // uczelnia stoi także w bezpiecznej przystani
        $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/buildings", ['type' => 'uczelnia']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(
            ['spichlerze' => 'available', 'mapy-sztormowe' => 'available', 'sonar-dalekiego-zasiegu' => 'available', 'zegluga-dwusektorowa' => 'locked', 'pojemne-ladownie' => 'available', 'dok-remontowy' => 'locked', 'lepsze-narzedzia' => 'locked', 'niszczyciel-zwiadowczy' => 'locked', 'nawigacja-oceaniczna' => 'locked'],
            $this->states($this->expedition($client, $profileId)),
        );

        $started = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/research", ['technology' => 'spichlerze']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame([
            'island' => 'zatoka-rozbitka',
            'technology' => 'spichlerze',
            'ticksLeft' => 6,
            'stockpile' => ['wood' => 50, 'steel' => 60, 'gold' => 80],
            'cargo' => ['wood' => 0, 'steel' => 0, 'gold' => 0],
        ], $started);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame([
            'technology' => 'spichlerze',
            'island' => 'zatoka-rozbitka',
            'islandName' => 'Zatoka Rozbitka',
            'progress' => 0,
            'ticks' => 6,
            'ticksLeft' => 6,
            'paused' => false,
        ], $expedition['research']['current']);
        self::assertSame(['id' => 'spichlerze', 'branch' => 'osady', 'level' => 1, 'state' => 'in-progress', 'ticksLeft' => 6, 'blocker' => null], $expedition['research']['technologies'][0]);
        self::assertSame('busy', $expedition['research']['technologies'][1]['blocker']);

        // drugie badanie czeka na koniec pierwszego
        $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/research", ['technology' => 'mapy-sztormowe']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('RESEARCH_IN_PROGRESS', $this->lastErrorCode($client));

        $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/research", ['technology' => 'atlantydzkie-zagle']);
        self::assertResponseStatusCodeSame(400);
        $this->post($client, "/api/profiles/$profileId/islands/atlantyda/research", ['technology' => 'mapy-sztormowe']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testResearchIsRejectedAwayFromAcademy(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAt(
            $client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka', 'mielizny'],
            buildings: ['mielizny' => [BuildingType::Uczelnia]],
        );

        $this->post($client, "/api/profiles/$profileId/islands/mielizny/research", ['technology' => 'spichlerze']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_ISLAND', $this->lastErrorCode($client));

        $poor = $this->captainAt($client, 'mielizny', owned: ['mielizny'], buildings: ['mielizny' => [BuildingType::Uczelnia]], stock: new Resources(20, 20, 4));
        self::assertSame('resources', $this->expedition($client, $poor)['research']['technologies'][0]['blocker']);
        $this->post($client, "/api/profiles/$poor/islands/mielizny/research", ['technology' => 'spichlerze']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_ENOUGH_RESOURCES', $this->lastErrorCode($client));
    }

    public function testFinishedResearchWorksAndLostAcademyPausesIt(): void
    {
        $client = static::createClient();

        // Spichlerze kończą się na tiku 6, potem złoże Mielizn zbiera do 20 tików produkcji (3 × 20)
        $granary = $this->captainAt(
            $client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka', 'mielizny'], tick: 30,
            buildings: ['zatoka-rozbitka' => [BuildingType::Uczelnia]],
            researched: [Technology::MapySztormowe],
            research: ['technology' => Technology::Spichlerze, 'island' => 'zatoka-rozbitka', 'progress' => 0, 'countedTo' => 0],
        );
        $expedition = $this->expedition($client, $granary);
        self::assertNull($expedition['research']['current']);
        self::assertSame(
            ['spichlerze' => 'researched', 'mapy-sztormowe' => 'researched', 'sonar-dalekiego-zasiegu' => 'available', 'zegluga-dwusektorowa' => 'locked', 'pojemne-ladownie' => 'available', 'dok-remontowy' => 'locked', 'lepsze-narzedzia' => 'locked', 'niszczyciel-zwiadowczy' => 'locked', 'nawigacja-oceaniczna' => 'locked'],
            $this->states($expedition),
        );
        $mielizny = array_column($expedition['islands'], null, 'id')['mielizny'];
        self::assertSame(['wood' => 60, 'steel' => 0, 'gold' => 0], $mielizny['deposit']['cap']);
        self::assertSame(60, $mielizny['stockpile']['wood']);
        // Mapy sztormowe
        self::assertSame(10, $expedition['world']['stormChance']);
        self::assertSame(20, $this->expedition($client, $this->captainAt($client, 'mielizny', owned: ['mielizny']))['world']['stormChance']);

        // uczelnia na wyspie bez bandery: badanie stoi
        $paused = $this->captainAt(
            $client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka'], tick: 9,
            buildings: ['mielizny' => [BuildingType::Uczelnia]],
            research: ['technology' => Technology::MapySztormowe, 'island' => 'mielizny', 'progress' => 2, 'countedTo' => 4],
        );
        $current = $this->expedition($client, $paused)['research']['current'];
        self::assertSame(['progress' => 2, 'ticksLeft' => 4, 'paused' => true], array_intersect_key($current, ['progress' => 0, 'ticksLeft' => 0, 'paused' => 0]));
    }

    /**
     * Kapitan z flotą startową zakotwiczony przy wyspie, z magazynem tej wyspy.
     *
     * @param list<string>                                                                      $owned
     * @param array<string, list<BuildingType>>                                                 $buildings
     * @param list<Technology>                                                                  $researched
     * @param array{technology: Technology, island: string, progress: int, countedTo: int}|null $research
     */
    private function captainAt(
        KernelBrowser $client,
        string $islandId,
        array $owned,
        array $buildings = [],
        ?Resources $stock = null,
        int $tick = 0,
        array $researched = [],
        ?array $research = null,
    ): string {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 40, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition($islandId),
            tick: $tick,
            stockpiles: [$islandId => $stock ?? new Resources(100, 100, 100)],
            depositTicks: array_fill_keys($owned, 0),
            ownedIslands: array_fill_keys($owned, 0),
            buildings: $buildings,
            researched: $researched,
            research: $research,
        ));

        return $profileId;
    }

    /**
     * @param array<string,mixed> $expedition
     *
     * @return array<string, string> technologia → stan
     */
    private function states(array $expedition): array
    {
        return array_column($expedition['research']['technologies'], 'state', 'id');
    }

    /** @return array<string,mixed> */
    private function expedition(KernelBrowser $client, string $profileId): array
    {
        $client->request('GET', "/api/profiles/$profileId/expedition");
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function lastErrorCode(KernelBrowser $client): ?string
    {
        $body = json_decode($client->getResponse()->getContent() ?: '{}', true);

        return is_array($body) ? ($body['error']['code'] ?? null) : null;
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
