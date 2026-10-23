<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\LogEntry;
use App\Domain\Expedition\LogEvent;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Dziennik kapitana (#111): zapis w profilu i widok w /expedition. */
final class EventLogApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testNewProfileStartsWithEmptyLog(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        self::assertSame([], $this->expedition($client, $profileId)['log']);
    }

    public function testBuildsAreLoggedNewestFirstAndSurviveReload(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAt($client, 'mielizny', tick: 7, owned: ['mielizny' => 0], stock: new Resources(wood: 35, steel: 10));

        $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'tratwa', 'islandId' => 'mielizny']);
        self::assertResponseStatusCodeSame(201);
        $this->post($client, "/api/profiles/$profileId/islands/mielizny/buildings", ['type' => 'tartak']);
        self::assertResponseStatusCodeSame(201);

        $expected = [
            ['tick' => 7, 'event' => 'building-built', 'building' => 'tartak', 'island' => 'mielizny', 'islandName' => 'Mielizny'],
            ['tick' => 7, 'event' => 'ship-built', 'ship' => 'tratwa', 'island' => 'mielizny', 'islandName' => 'Mielizny'],
        ];
        self::assertSame($expected, $this->expedition($client, $profileId)['log']);

        // przeładowanie strony (nowy klient, profil z bazy) pokazuje te same wpisy
        self::ensureKernelShutdown();
        $client = static::createClient();
        self::assertSame($expected, $this->expedition($client, $profileId)['log']);
    }

    public function testCounterattackStruckInAbsenceIsLoggedAtTheStrikeTick(): void
    {
        $client = static::createClient();
        // zapowiedź z tiku 18 jest już w dzienniku; uderzenie w 30 rozstrzyga się leniwie przy odczycie w tiku 32
        $profileId = $this->captainAt(
            $client, 'wyspa-sygnalowa', tick: 32, owned: ['wyspa-sygnalowa' => 0], stock: new Resources(21, 7, 3),
            pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 18, 'strikeAt' => 30, 'defense' => null],
            log: [new LogEntry(18, LogEvent::CounterattackAnnounced, ['island' => 'wyspa-sygnalowa', 'strikeAt' => 30])],
        );

        $log = $this->expedition($client, $profileId)['log'];

        self::assertSame([
            ['tick' => 30, 'event' => 'island-lost', 'island' => 'wyspa-sygnalowa', 'absent' => true, 'stockpileLost' => ['wood' => 10, 'steel' => 3, 'gold' => 1], 'islandName' => 'Wyspa Sygnałowa'],
            ['tick' => 18, 'event' => 'counterattack-announced', 'island' => 'wyspa-sygnalowa', 'strikeAt' => 30, 'islandName' => 'Wyspa Sygnałowa'],
        ], $log);
        // odczyt nie zapisuje profilu, ale rozstrzygnięcie jest deterministyczne — drugi odczyt widzi to samo
        self::assertSame($log, $this->expedition($client, $profileId)['log']);
    }

    public function testSnapshotKeepsTheLogAndProfileWithoutLogLoads(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAt($client, 'mielizny', tick: 12, owned: ['mielizny' => 0], stock: Resources::none(), log: [
            new LogEntry(9, LogEvent::GatePassed, ['gate' => 'strzezona-ciesnina', 'from' => 'ocean-sztormow', 'to' => 'morze-rozbitkow']),
            new LogEntry(4, LogEvent::Storm, ['effect' => 'ship-damaged', 'ship' => 'kuter']),
            new LogEntry(11, LogEvent::ResearchCompleted, ['technology' => 'mapy-sztormowe', 'island' => 'mielizny']),
        ]);

        $state = $this->storedState($profileId);
        self::assertSame([
            ['tick' => 4, 'event' => 'storm', 'data' => ['effect' => 'ship-damaged', 'ship' => 'kuter']],
            ['tick' => 9, 'event' => 'gate-passed', 'data' => ['gate' => 'strzezona-ciesnina', 'from' => 'ocean-sztormow', 'to' => 'morze-rozbitkow']],
            ['tick' => 11, 'event' => 'research-completed', 'data' => ['technology' => 'mapy-sztormowe', 'island' => 'mielizny']],
        ], $state['log']);

        $log = $this->expedition($client, $profileId)['log'];
        self::assertSame(['research-completed', 'gate-passed', 'storm'], array_column($log, 'event'));
        self::assertSame('Ocean Sztormów', $log[1]['fromName']);
        self::assertSame('Morze Rozbitków', $log[1]['toName']);
        self::assertSame('Strzeżona cieśnina', $log[1]['gateName']);

        // profil sprzed dziennika (brak klucza `log`) i wpis nieznanego rodzaju
        unset($state['log']);
        $this->storeState($profileId, $state);
        self::assertSame([], $this->expedition($client, $profileId)['log']);

        $state['log'] = [['tick' => 3, 'event' => 'kraken', 'data' => []], ['tick' => 5, 'event' => 'storm', 'data' => ['effect' => 'cargo-lost']]];
        $this->storeState($profileId, $state);
        self::assertSame([['tick' => 5, 'event' => 'storm', 'effect' => 'cargo-lost']], $this->expedition($client, $profileId)['log']);
    }

    /**
     * Kapitan (marynarz) zakotwiczony na wyspie z zadanymi banderami, magazynem,
     * kontratakiem i dziennikiem.
     *
     * @param array<string, int>                                                        $owned
     * @param array{island:string, announcedAt:int, strikeAt:int, defense:?string}|null $pending
     * @param list<LogEntry>                                                            $log
     */
    private function captainAt(KernelBrowser $client, string $islandId, int $tick, array $owned, Resources $stock, ?array $pending = null, array $log = []): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 80, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition($islandId), null, 0, $tick, null,
            [$islandId => $stock], null, array_fill_keys(array_keys($owned), $tick), $owned,
            ['pending' => $pending, 'calmFrom' => $tick, 'rolledTo' => $tick - 1, 'last' => null],
            log: $log,
        ));

        return $profileId;
    }

    /** @return array<string,mixed> */
    private function storedState(string $profileId): array
    {
        $json = static::getContainer()->get(Connection::class)->fetchOne('SELECT state FROM profiles WHERE id = ?', [$profileId]);

        return json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
    }

    /** @param array<string,mixed> $state */
    private function storeState(string $profileId, array $state): void
    {
        static::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE profiles SET state = ? WHERE id = ?',
            [json_encode($state, JSON_THROW_ON_ERROR), $profileId],
        );
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
