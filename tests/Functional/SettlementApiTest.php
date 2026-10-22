<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class SettlementApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testBuildSawmillDoublesDepositOnOwnedIsland(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAt($client, 'mielizny', owned: ['mielizny'], stock: new Resources(wood: 35, steel: 10));

        $expedition = $this->expedition($client, $profileId);
        self::assertSame([
            ['type' => 'tartak', 'buildCost' => ['wood' => 30, 'steel' => 10, 'gold' => 0]],
            ['type' => 'kopalnia', 'buildCost' => ['wood' => 30, 'steel' => 20, 'gold' => 0]],
            ['type' => 'rozbudowa-stoczni', 'buildCost' => ['wood' => 50, 'steel' => 30, 'gold' => 15]],
            ['type' => 'fort', 'buildCost' => ['wood' => 40, 'steel' => 40, 'gold' => 10]],
        ], $expedition['buildingTypes']);
        $mielizny = $this->island($expedition, 'mielizny');
        self::assertSame([], $mielizny['buildings']);
        self::assertSame(['tartak' => null, 'kopalnia' => 'site', 'rozbudowa-stoczni' => 'site', 'fort' => 'resources'], $mielizny['buildBlockers']);
        self::assertSame(['tartak' => 'not-owned', 'kopalnia' => 'not-owned', 'rozbudowa-stoczni' => 'not-owned', 'fort' => 'not-owned'], $this->island($expedition, 'archipelag-mgiel')['buildBlockers']);

        $built = $this->post($client, "/api/profiles/$profileId/islands/mielizny/buildings", ['type' => 'tartak']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['tartak'], $built['buildings']);
        self::assertSame(['wood' => 5, 'steel' => 0, 'gold' => 0], $built['stockpile']);

        $mielizny = $this->island($this->expedition($client, $profileId), 'mielizny');
        self::assertSame(['tartak'], $mielizny['buildings']);
        self::assertSame(['tartak' => 'already-built', 'kopalnia' => 'site', 'rozbudowa-stoczni' => 'site', 'fort' => 'resources'], $mielizny['buildBlockers']);
        self::assertSame([
            'yield' => ['wood' => 6, 'steel' => 0, 'gold' => 0],
            'cap' => ['wood' => 60, 'steel' => 0, 'gold' => 0],
            'active' => true,
        ], $mielizny['deposit']);

        // drugi tartak na tej samej wyspie
        $this->post($client, "/api/profiles/$profileId/islands/mielizny/buildings", ['type' => 'tartak']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('BUILDING_ALREADY_STANDING', $this->lastErrorCode($client));
    }

    public function testBuildIsRejected(): void
    {
        $client = static::createClient();
        $rich = new Resources(100, 100, 100);

        $stranger = $this->captainAt($client, 'mielizny', owned: [], stock: $rich);
        $this->post($client, "/api/profiles/$stranger/islands/mielizny/buildings", ['type' => 'tartak']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('ISLAND_NOT_OWNED', $this->lastErrorCode($client));

        $away = $this->captainAt($client, 'zatoka-rozbitka', owned: ['mielizny'], stock: $rich);
        $this->post($client, "/api/profiles/$away/islands/mielizny/buildings", ['type' => 'tartak']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_ISLAND', $this->lastErrorCode($client));

        $owner = $this->captainAt($client, 'mielizny', owned: ['mielizny'], stock: new Resources(wood: 30, steel: 19));
        $this->post($client, "/api/profiles/$owner/islands/mielizny/buildings", ['type' => 'kopalnia']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('BUILDING_SITE_UNSUITABLE', $this->lastErrorCode($client));

        self::assertSame(['tartak' => null, 'kopalnia' => 'site', 'rozbudowa-stoczni' => 'site', 'fort' => 'resources'], $this->island($this->expedition($client, $owner), 'mielizny')['buildBlockers']);
        $poor = $this->captainAt($client, 'mielizny', owned: ['mielizny'], stock: new Resources(wood: 29, steel: 10));
        self::assertSame('resources', $this->island($this->expedition($client, $poor), 'mielizny')['buildBlockers']['tartak']);
        $this->post($client, "/api/profiles/$poor/islands/mielizny/buildings", ['type' => 'tartak']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_ENOUGH_RESOURCES', $this->lastErrorCode($client));

        $this->post($client, "/api/profiles/$owner/islands/mielizny/buildings", ['type' => 'uczelnia']);
        self::assertResponseStatusCodeSame(400);
        $this->post($client, "/api/profiles/$owner/islands/atlantyda/buildings", ['type' => 'tartak']);
        self::assertResponseStatusCodeSame(404);
    }

    public function testFortStandsOnOwnedIslandButNotInShipwreckBay(): void
    {
        $client = static::createClient();
        $rich = new Resources(100, 100, 100);

        // Zatoka Rozbitka to bezpieczna przystań: kontrataki jej nie dotyczą, fortu się tam nie stawia
        $harbor = $this->captainAt($client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka'], stock: $rich);
        self::assertSame('site', $this->island($this->expedition($client, $harbor), 'zatoka-rozbitka')['buildBlockers']['fort']);
        $this->post($client, "/api/profiles/$harbor/islands/zatoka-rozbitka/buildings", ['type' => 'fort']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('BUILDING_SITE_UNSUITABLE', $this->lastErrorCode($client));

        $owner = $this->captainAt($client, 'mielizny', owned: ['mielizny'], stock: $rich);
        self::assertNull($this->island($this->expedition($client, $owner), 'mielizny')['buildBlockers']['fort']);
        $built = $this->post($client, "/api/profiles/$owner/islands/mielizny/buildings", ['type' => 'fort']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['fort'], $built['buildings']);
        self::assertSame(['wood' => 60, 'steel' => 60, 'gold' => 90], $built['stockpile']);

        $mielizny = $this->island($this->expedition($client, $owner), 'mielizny');
        self::assertSame(['fort'], $mielizny['buildings']);
        self::assertSame('already-built', $mielizny['buildBlockers']['fort']);
        // fort nie zmienia złoża
        self::assertSame(['wood' => 3, 'steel' => 0, 'gold' => 0], $mielizny['deposit']['yield']);
    }

    /**
     * Kapitan z flotą startową zakotwiczony przy wyspie, z magazynem tej wyspy.
     *
     * @param list<string> $owned
     */
    private function captainAt(KernelBrowser $client, string $islandId, array $owned, Resources $stock): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 40, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition($islandId),
            stockpiles: [$islandId => $stock],
            depositTicks: array_fill_keys($owned, 0),
            ownedIslands: array_fill_keys($owned, 0),
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
