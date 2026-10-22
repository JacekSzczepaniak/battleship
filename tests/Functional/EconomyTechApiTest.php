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
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Technologie ekonomiczne (#96): Pojemne ładownie, Dok remontowy, Lepsze narzędzia. */
final class EconomyTechApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testSecondLevelNeedsFirstLevelAndHelmsmanRank(): void
    {
        $client = static::createClient();
        $academy = ['mielizny' => [BuildingType::Uczelnia]];

        // bez Pojemnych ładowni Dok remontowy czeka na poziom 1 Kadłubów
        $noHolds = $this->captainAt($client, 'mielizny', owned: ['mielizny'], buildings: $academy, xp: 250);
        self::assertSame('prerequisite', $this->technology($this->expedition($client, $noHolds), 'dok-remontowy')['blocker']);
        $this->post($client, "/api/profiles/$noHolds/islands/mielizny/research", ['technology' => 'dok-remontowy']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('TECHNOLOGY_PREREQUISITE', $this->lastErrorCode($client));

        // ze Spichlerzami, ale poniżej rangi sternik (250 XP)
        $bosun = $this->captainAt($client, 'mielizny', owned: ['mielizny'], buildings: $academy, xp: 249, researched: [Technology::Spichlerze]);
        self::assertSame('rank', $this->technology($this->expedition($client, $bosun), 'lepsze-narzedzia')['blocker']);
        $this->post($client, "/api/profiles/$bosun/islands/mielizny/research", ['technology' => 'lepsze-narzedzia']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('TECHNOLOGY_LOCKED', $this->lastErrorCode($client));

        // sternik ze Spichlerzami zaczyna Lepsze narzędzia: koszt i czas poziomu 2
        $helmsman = $this->captainAt($client, 'mielizny', owned: ['mielizny'], buildings: $academy, xp: 250, researched: [Technology::Spichlerze]);
        self::assertSame(
            ['id' => 'lepsze-narzedzia', 'branch' => 'osady', 'level' => 2, 'state' => 'available', 'ticksLeft' => null, 'blocker' => null],
            $this->technology($this->expedition($client, $helmsman), 'lepsze-narzedzia'),
        );
        $started = $this->post($client, "/api/profiles/$helmsman/islands/mielizny/research", ['technology' => 'lepsze-narzedzia']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(10, $started['ticksLeft']);
        self::assertSame(['wood' => 60, 'steel' => 70, 'gold' => 85], $started['stockpile']);
    }

    public function testResearchedEconomyTechsChangeCapacityRepairAndDeposit(): void
    {
        $client = static::createClient();
        $fleet = [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Niszczyciel), OwnedShip::build(ShipType::Tratwa)];
        [$damagedKuter, , $niszczyciel, $tratwa] = $fleet;
        foreach ([$damagedKuter, $niszczyciel, $tratwa] as $ship) {
            $ship->markDamaged();
        }

        // Mielizny z tartakiem pod banderą od tiku 0, teraz tik 5
        $profileId = $this->captainAt(
            $client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka', 'mielizny'], tick: 5,
            buildings: ['mielizny' => [BuildingType::Tartak]],
            stock: new Resources(wood: 10),
            fleet: $fleet,
            cargo: new Resources(wood: 40, steel: 20, gold: 10),
            researched: [Technology::Spichlerze, Technology::PojemneLadownie, Technology::DokRemontowy, Technology::LepszeNarzedzia],
        );

        $expedition = $this->expedition($client, $profileId);

        // Pojemne ładownie: 22 + 22 + 30 + 0, ładunek ponad bazowe 50 zostaje
        self::assertSame(74, $expedition['profile']['cargoCapacity']);
        self::assertSame(['wood' => 40, 'steel' => 20, 'gold' => 10], $expedition['profile']['cargo']);
        $types = array_column($expedition['shipTypes'], null, 'type');
        self::assertSame([0, 22, 30, 45, 90], array_values(array_column($types, 'cargoCapacity')));

        // Dok remontowy: połowa w górę, tratwa za darmo
        self::assertSame(['wood' => 4, 'steel' => 0, 'gold' => 0], $types['kuter']['repairCost']);
        self::assertSame(['wood' => 5, 'steel' => 5, 'gold' => 0], $types['niszczyciel']['repairCost']);
        self::assertSame(['wood' => 10, 'steel' => 13, 'gold' => 0], $types['lotniskowiec']['repairCost']);
        self::assertSame(['wood' => 8, 'steel' => 3, 'gold' => 0], $types['transportowiec']['repairCost']);
        self::assertSame(
            [['wood' => 4, 'steel' => 0, 'gold' => 0], ['wood' => 4, 'steel' => 0, 'gold' => 0], ['wood' => 5, 'steel' => 5, 'gold' => 0], ['wood' => 0, 'steel' => 0, 'gold' => 0]],
            array_column($expedition['fleet'], 'repairCost'),
        );

        // Lepsze narzędzia: (3 + 1) × 2 z tartakiem, limit 8 × 20 tików ze Spichlerzami, 5 tików urobku
        $mielizny = array_column($expedition['islands'], null, 'id')['mielizny'];
        self::assertSame(['wood' => 8, 'steel' => 0, 'gold' => 0], $mielizny['deposit']['yield']);
        self::assertSame(['wood' => 160, 'steel' => 0, 'gold' => 0], $mielizny['deposit']['cap']);
        self::assertSame(['wood' => 40, 'steel' => 0, 'gold' => 0], $mielizny['stockpile']);

        // remont kutra w Zatoce płaci 4 drewna z magazynu wyspy, tratwy nic
        $this->post($client, "/api/profiles/$profileId/ships/{$damagedKuter->id}/repair", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseIsSuccessful();
        $this->post($client, "/api/profiles/$profileId/ships/{$tratwa->id}/repair", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseIsSuccessful();

        $after = $this->expedition($client, $profileId);
        $zatoka = array_column($after['islands'], null, 'id')['zatoka-rozbitka'];
        self::assertSame(['wood' => 6, 'steel' => 0, 'gold' => 0], $zatoka['stockpile']);
        self::assertSame(['wood' => 40, 'steel' => 20, 'gold' => 10], $after['profile']['cargo']);
        self::assertSame([false, false, true, false], array_column($after['fleet'], 'damaged'));
    }

    public function testWithoutTechsValuesStayAsShipTypeDefines(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAt($client, 'zatoka-rozbitka', owned: ['zatoka-rozbitka', 'mielizny'], buildings: ['mielizny' => [BuildingType::Tartak]]);

        $expedition = $this->expedition($client, $profileId);
        $types = array_column($expedition['shipTypes'], null, 'type');
        self::assertSame([0, 15, 20, 30, 60], array_values(array_column($types, 'cargoCapacity')));
        self::assertSame(['wood' => 8, 'steel' => 0, 'gold' => 0], $types['kuter']['repairCost']);
        self::assertSame(['wood' => 6, 'steel' => 0, 'gold' => 0], array_column($expedition['islands'], null, 'id')['mielizny']['deposit']['yield']);
    }

    /**
     * Kapitan zakotwiczony przy wyspie, z magazynem tej wyspy.
     *
     * @param list<string>                      $owned
     * @param array<string, list<BuildingType>> $buildings
     * @param list<OwnedShip>|null              $fleet
     * @param list<Technology>                  $researched
     */
    private function captainAt(
        KernelBrowser $client,
        string $islandId,
        array $owned,
        array $buildings = [],
        ?Resources $stock = null,
        int $tick = 0,
        int $xp = 40,
        ?array $fleet = null,
        ?Resources $cargo = null,
        array $researched = [],
    ): string {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), $xp, [], $fleet ?? $profile->fleet(), $cargo ?? Resources::none(),
            $profile->worldSeed(), $world->islandPosition($islandId),
            tick: $tick,
            stockpiles: [$islandId => $stock ?? new Resources(100, 100, 100)],
            depositTicks: array_fill_keys($owned, 0),
            ownedIslands: array_fill_keys($owned, 0),
            buildings: $buildings,
            researched: $researched,
        ));

        return $profileId;
    }

    /**
     * @param array<string,mixed> $expedition
     *
     * @return array<string,mixed>
     */
    private function technology(array $expedition, string $id): array
    {
        return array_column($expedition['research']['technologies'], null, 'id')[$id];
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
