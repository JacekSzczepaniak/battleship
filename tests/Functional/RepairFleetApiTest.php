<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Remont całej floty w stoczni jedną akcją (#151). */
final class RepairFleetApiTest extends WebTestCase
{
    private const KUTER = 'c1f1f6f0-2c9c-4a51-9d0e-1f4c1d5e7a01';
    private const NISZCZYCIEL = 'c1f1f6f0-2c9c-4a51-9d0e-1f4c1d5e7a02';
    private const LOTNISKOWIEC = 'c1f1f6f0-2c9c-4a51-9d0e-1f4c1d5e7a03';
    private const SPRAWNY = 'c1f1f6f0-2c9c-4a51-9d0e-1f4c1d5e7a04';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testRepairsCheapestFirstAndReportsSkippedShipsWithReason(): void
    {
        $client = static::createClient();
        // stocznia Zatoki ma poziom 1: niszczyciel i lotniskowiec są za ciężkie; kuter płaci magazyn i ładownia
        $profileId = $this->captainAt($client, 'zatoka-rozbitka', new Resources(wood: 5, steel: 40), new Resources(wood: 3));
        $tick = $this->expedition($client, $profileId)['world']['tick'];

        $body = $this->post($client, "/api/profiles/$profileId/fleet/repair", ['islandId' => 'zatoka-rozbitka']);

        self::assertResponseIsSuccessful();
        self::assertSame([self::KUTER], array_column($body['repaired'], 'id'));
        self::assertFalse($body['repaired'][0]['damaged']);
        self::assertSame(
            [[self::NISZCZYCIEL, 'shipyard-too-low'], [self::LOTNISKOWIEC, 'shipyard-too-low']],
            array_map(static fn (array $skip) => [$skip['ship']['id'], $skip['reason']], $body['skipped']),
        );
        self::assertSame(['wood' => 8, 'steel' => 0, 'gold' => 0], $body['paid']);
        self::assertSame(['wood' => 0, 'steel' => 40, 'gold' => 0], $body['stockpile']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $body['cargo']);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame($tick, $expedition['world']['tick']);
        self::assertSame(
            [self::LOTNISKOWIEC => true, self::NISZCZYCIEL => true, self::SPRAWNY => false, self::KUTER => false],
            array_column($expedition['fleet'], 'damaged', 'id'),
        );
    }

    public function testSkipsShipsWhenResourcesRunOut(): void
    {
        $client = static::createClient();
        // Wyspa Sygnałowa ma stocznię poziomu 3: wystarczy na kuter i niszczyciel, lotniskowcowi brakuje stali
        $profileId = $this->captainAt($client, 'wyspa-sygnalowa', new Resources(wood: 40, steel: 20));

        $body = $this->post($client, "/api/profiles/$profileId/fleet/repair", ['islandId' => 'wyspa-sygnalowa']);

        self::assertResponseIsSuccessful();
        self::assertSame([self::KUTER, self::NISZCZYCIEL], array_column($body['repaired'], 'id'));
        self::assertSame([[self::LOTNISKOWIEC, 'not-enough-resources']], array_map(static fn (array $skip) => [$skip['ship']['id'], $skip['reason']], $body['skipped']));
        self::assertSame(['wood' => 22, 'steel' => 10, 'gold' => 0], $body['stockpile']);
    }

    public function testRejectsIslandWithoutShipyardOrAway(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAt($client, 'mielizny', new Resources(wood: 100, steel: 100));

        $this->post($client, "/api/profiles/$profileId/fleet/repair", ['islandId' => 'mielizny']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_SHIPYARD', $this->lastErrorCode($client));

        $this->post($client, "/api/profiles/$profileId/fleet/repair", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_ISLAND', $this->lastErrorCode($client));

        $this->post($client, "/api/profiles/$profileId/fleet/repair");
        self::assertResponseStatusCodeSame(400);
    }

    /** Kapitan przy wyspie z uszkodzonym kutrem, niszczycielem i lotniskowcem oraz sprawnym kutrem. */
    private function captainAt(KernelBrowser $client, string $islandId, Resources $stock, ?Resources $cargo = null): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $fleet = [
            new OwnedShip(self::LOTNISKOWIEC, ShipType::Lotniskowiec, damaged: true),
            new OwnedShip(self::NISZCZYCIEL, ShipType::Niszczyciel, damaged: true),
            new OwnedShip(self::SPRAWNY, ShipType::Kuter),
            new OwnedShip(self::KUTER, ShipType::Kuter, damaged: true),
        ];
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 600, [], $fleet, $cargo ?? Resources::none(),
            $profile->worldSeed(), $world->islandPosition($islandId),
            stockpiles: [$islandId => $stock],
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
