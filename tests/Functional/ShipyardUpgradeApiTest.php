<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ShipyardUpgradeApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testUpgradedBayBuildsTransportAndDestroyerButNotCarrier(): void
    {
        $client = static::createClient();
        // ranga kapitana (600 XP) odblokowuje też lotniskowiec — blokuje go tylko stocznia
        $profileId = $this->captainAt($client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka'], xp: 600, stock: new Resources(200, 100, 15));

        $zatoka = $this->island($this->expedition($client, $profileId), 'zatoka-rozbitka');
        self::assertSame(1, $zatoka['shipyardLevel']);
        self::assertNull($zatoka['buildBlockers']['rozbudowa-stoczni']);

        $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'transportowiec', 'islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SHIPYARD_TOO_LOW', $this->lastErrorCode($client));

        $built = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/buildings", ['type' => 'rozbudowa-stoczni']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['rozbudowa-stoczni'], $built['buildings']);
        self::assertSame(['wood' => 150, 'steel' => 70, 'gold' => 0], $built['stockpile']);

        $zatoka = $this->island($this->expedition($client, $profileId), 'zatoka-rozbitka');
        self::assertSame(2, $zatoka['shipyardLevel']);
        self::assertSame('already-built', $zatoka['buildBlockers']['rozbudowa-stoczni']);

        foreach (['transportowiec', 'niszczyciel'] as $type) {
            $this->post($client, "/api/profiles/$profileId/ships", ['type' => $type, 'islandId' => 'zatoka-rozbitka']);
            self::assertResponseStatusCodeSame(201);
        }

        $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'lotniskowiec', 'islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SHIPYARD_TOO_LOW', $this->lastErrorCode($client));
    }

    public function testUpgradeIsRejectedOnSignalIslandAndWithoutShipyard(): void
    {
        $client = static::createClient();
        $rich = new Resources(100, 100, 100);

        foreach (['wyspa-sygnalowa', 'mielizny'] as $islandId) {
            $profileId = $this->captainAt($client, $islandId, owned: [$islandId], xp: 80, stock: $rich);
            self::assertSame('site', $this->island($this->expedition($client, $profileId), $islandId)['buildBlockers']['rozbudowa-stoczni']);

            $this->post($client, "/api/profiles/$profileId/islands/$islandId/buildings", ['type' => 'rozbudowa-stoczni']);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('BUILDING_SITE_UNSUITABLE', $this->lastErrorCode($client));
        }
    }

    public function testUpgradeWithoutFlagFallsBackToCatalogLevel(): void
    {
        $client = static::createClient();
        $destroyer = new OwnedShip('b6f1f6f0-2c9c-4a51-9d0e-1f4c1d5e7a01', ShipType::Niszczyciel, damaged: true);
        $upgraded = ['zatoka-rozbitka' => [BuildingType::RozbudowaStoczni]];
        $stock = new Resources(50, 50, 0);

        $lost = $this->captainAt($client, 'zatoka-rozbitka', owned: [], xp: 80, stock: $stock, buildings: $upgraded, fleet: [$destroyer]);
        self::assertSame(1, $this->island($this->expedition($client, $lost), 'zatoka-rozbitka')['shipyardLevel']);
        $this->post($client, "/api/profiles/$lost/ships/{$destroyer->id}/repair", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SHIPYARD_TOO_LOW', $this->lastErrorCode($client));

        $owner = $this->captainAt($client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka'], xp: 80, stock: $stock, buildings: $upgraded, fleet: [$destroyer]);
        $repaired = $this->post($client, "/api/profiles/$owner/ships/{$destroyer->id}/repair", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseIsSuccessful();
        self::assertFalse($repaired['damaged']);
    }

    /**
     * Kapitan zakotwiczony przy wyspie, z magazynem tej wyspy i budynkami.
     *
     * @param list<string>                      $owned
     * @param array<string, list<BuildingType>> $buildings
     * @param list<OwnedShip>|null              $fleet     null = flota startowa
     */
    private function captainAt(KernelBrowser $client, string $islandId, array $owned, int $xp, Resources $stock, array $buildings = [], ?array $fleet = null): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), $xp, [], $fleet ?? $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition($islandId),
            stockpiles: [$islandId => $stock],
            depositTicks: array_fill_keys($owned, 0),
            ownedIslands: array_fill_keys($owned, 0),
            buildings: $buildings,
        ));

        return $profileId;
    }

    /**
     * @param array<string,mixed> $expedition
     *
     * @return array<string,mixed>
     */
    private function island(array $expedition, string $islandId): array
    {
        return array_column($expedition['islands'], null, 'id')[$islandId];
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
