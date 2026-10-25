<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Kuter remontowy: ograniczone naprawy floty poza stocznią (#152). */
final class RepairCutterApiTest extends WebTestCase
{
    private const CUTTER = 'd2a1f6f0-2c9c-4a51-9d0e-1f4c1d5e7b01';
    private const KUTER = 'd2a1f6f0-2c9c-4a51-9d0e-1f4c1d5e7b02';
    private const NISZCZYCIEL = 'd2a1f6f0-2c9c-4a51-9d0e-1f4c1d5e7b03';
    private const LOTNISKOWIEC = 'd2a1f6f0-2c9c-4a51-9d0e-1f4c1d5e7b04';
    private const TRATWA = 'd2a1f6f0-2c9c-4a51-9d0e-1f4c1d5e7b05';
    private const TRANSPORTOWIEC = 'd2a1f6f0-2c9c-4a51-9d0e-1f4c1d5e7b06';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testShipyardBuildsRepairCutterOnlyAfterResearch(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, [OwnedShip::build(ShipType::Kuter)], Resources::none());

        $types = array_column($this->expedition($client, $profileId)['shipTypes'], null, 'type');
        self::assertContains(
            ['variant' => 'kuter-remontowy', 'requiredTechnology' => 'kuter-remontowy', 'unlock' => 'academy', 'unlocked' => false],
            $types['kuter']['variants'],
        );

        $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'kuter', 'islandId' => 'zatoka-rozbitka', 'variant' => 'kuter-remontowy']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('SHIP_VARIANT_LOCKED', $this->lastErrorCode($client));

        $profileId = $this->captain($client, [OwnedShip::build(ShipType::Kuter)], Resources::none(), researched: [Technology::DokRemontowy, Technology::KuterRemontowy]);
        $ship = $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'kuter', 'islandId' => 'zatoka-rozbitka', 'variant' => 'kuter-remontowy']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('kuter-remontowy', $ship['variant']);

        $expedition = $this->expedition($client, $profileId);
        // koszt jak zwykłego kutra: 20 drewna z magazynu
        self::assertSame(80, $expedition['islands'][array_search('zatoka-rozbitka', array_column($expedition['islands'], 'id'), true)]['stockpile']['wood']);
        $fleet = array_column($expedition['fleet'], null, 'id');
        self::assertFalse($fleet[$ship['id']]['armed']);
        self::assertTrue($expedition['profile']['fieldRepairs']);
    }

    public function testRepairsOneShipPerTickFromCargoAndLogsIt(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, $this->damagedFleet(), new Resources(wood: 20, steel: 10), researched: [Technology::DokRemontowy, Technology::KuterRemontowy]);
        $tick = $this->expedition($client, $profileId)['world']['tick'];

        // przeładunek = tik: kuter (2 pola) przed niszczycielem, lotniskowiec czeka na stocznię
        $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'zatoka-rozbitka', 'direction' => 'load', 'gold' => 1]);
        self::assertResponseIsSuccessful();
        $expedition = $this->expedition($client, $profileId);
        self::assertSame(
            [self::CUTTER => false, self::KUTER => false, self::NISZCZYCIEL => true, self::LOTNISKOWIEC => true],
            array_column($expedition['fleet'], 'damaged', 'id'),
        );
        self::assertSame(['wood' => 16, 'steel' => 10, 'gold' => 1], $expedition['profile']['cargo']);
        self::assertSame(
            ['tick' => $tick + 1, 'event' => 'field-repair', 'ship' => 'kuter', 'paid' => ['wood' => 4, 'steel' => 0, 'gold' => 0]],
            $expedition['log'][0],
        );

        $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'zatoka-rozbitka', 'direction' => 'unload', 'gold' => 1]);
        $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'zatoka-rozbitka', 'direction' => 'load', 'gold' => 1]);
        $expedition = $this->expedition($client, $profileId);
        self::assertSame(
            [self::CUTTER => false, self::KUTER => false, self::NISZCZYCIEL => false, self::LOTNISKOWIEC => true],
            array_column($expedition['fleet'], 'damaged', 'id'),
        );
        self::assertSame(['wood' => 11, 'steel' => 5, 'gold' => 1], $expedition['profile']['cargo']);
        self::assertCount(2, array_filter($expedition['log'], static fn (array $e) => 'field-repair' === $e['event']));
    }

    public function testSwitchTurnsRepairsOff(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, $this->damagedFleet(), new Resources(wood: 20, steel: 10), researched: [Technology::DokRemontowy, Technology::KuterRemontowy]);

        $client->request('PUT', "/api/profiles/$profileId/fleet/field-repairs", server: ['CONTENT_TYPE' => 'application/json'], content: '{"enabled":"no"}');
        self::assertResponseStatusCodeSame(400);

        $client->request('PUT', "/api/profiles/$profileId/fleet/field-repairs", server: ['CONTENT_TYPE' => 'application/json'], content: '{"enabled":false}');
        self::assertResponseIsSuccessful();
        self::assertFalse($this->expedition($client, $profileId)['profile']['fieldRepairs']);

        $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'zatoka-rozbitka', 'direction' => 'load', 'gold' => 1]);
        $expedition = $this->expedition($client, $profileId);
        self::assertTrue(array_column($expedition['fleet'], 'damaged', 'id')[self::KUTER]);
        self::assertSame(['wood' => 20, 'steel' => 10, 'gold' => 1], $expedition['profile']['cargo']);
        self::assertSame([], array_filter($expedition['log'], static fn (array $e) => 'field-repair' === $e['event']));
    }

    public function testRepairCutterIsNoEscortAndRepairsAtBattleStart(): void
    {
        $client = static::createClient();
        $tratwa = new OwnedShip(self::TRATWA, ShipType::Tratwa, damaged: true);
        $profileId = $this->captain($client, [
            new OwnedShip(self::TRANSPORTOWIEC, ShipType::Transportowiec),
            new OwnedShip(self::CUTTER, ShipType::Kuter, variant: ShipVariant::KuterRemontowy),
            new OwnedShip(self::KUTER, ShipType::Kuter),
            $tratwa,
        ], Resources::none(), researched: [Technology::DokRemontowy, Technology::KuterRemontowy], xp: 0);

        $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle", ['shipIds' => [self::TRANSPORTOWIEC, self::CUTTER]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('CONVOY_UNESCORTED', $this->lastErrorCode($client));
        self::assertTrue(array_column($this->expedition($client, $profileId)['fleet'], 'damaged', 'id')[self::TRATWA]);

        // start bitwy to tik: kuter remontowy naprawia tratwę (remont bez kosztu)
        $gameId = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle", ['shipIds' => [self::KUTER, self::CUTTER]])['id'];
        self::assertResponseStatusCodeSame(201);
        self::assertFalse(array_column($this->expedition($client, $profileId)['fleet'], 'damaged', 'id')[self::TRATWA]);

        // w bitwie kuter remontowy to dwumasztowiec bez wyposażenia (wariant odbiegający od sonaru)
        $client->request('GET', "/api/games/$gameId");
        $game = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['2' => [[]]], $game['variants']);
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 2],
            ['x' => 0, 'y' => 2, 'o' => 'h', 'l' => 2, 'w' => []],
        ]]);
        self::assertResponseIsSuccessful();
    }

    /** @return list<OwnedShip> sprawny kuter remontowy, uszkodzony kuter, niszczyciel i lotniskowiec */
    private function damagedFleet(): array
    {
        return [
            new OwnedShip(self::CUTTER, ShipType::Kuter, variant: ShipVariant::KuterRemontowy),
            new OwnedShip(self::KUTER, ShipType::Kuter, damaged: true),
            new OwnedShip(self::NISZCZYCIEL, ShipType::Niszczyciel, damaged: true),
            new OwnedShip(self::LOTNISKOWIEC, ShipType::Lotniskowiec, damaged: true),
        ];
    }

    /**
     * Kapitan w Zatoce Rozbitka (stocznia poziomu 1) z magazynem drewna i złota.
     *
     * @param list<OwnedShip>  $fleet
     * @param list<Technology> $researched
     */
    private function captain(KernelBrowser $client, array $fleet, Resources $cargo, array $researched = [], int $xp = 600): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), $xp, [], $fleet, $cargo,
            $profile->worldSeed(), $profile->position(), $profile->discoveredSectors(), visited: $profile->visitedSectors(),
            stockpiles: ['zatoka-rozbitka' => new Resources(wood: 100, gold: 10)],
            researched: $researched,
        ));

        return $profileId;
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
