<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\Prize;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Zbadanie konstrukcji zdobytego statku (#121) przez API: decyzja „study”,
 * stocznia i drzewko po zbadaniu, uczelnia bez technologii ze zdobyczy
 * oraz warianty w składzie wroga na Oceanie Sztormów, Morzu Mgieł i u strażnika.
 */
final class ReverseEngineeringApiTest extends WebTestCase
{
    private const PRIZE = '5c4a0000-0000-4000-8000-000000000121';
    private const KNOWN_PRIZE = '5c4a0000-0000-4000-8000-000000000122';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testStudyUnlocksTheVariantInTheShipyardAndTheShipIsGone(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, Sea::START, 'zatoka-rozbitka', prizes: [
            new Prize(self::PRIZE, ShipType::Niszczyciel, ShipVariant::CiezkiNiszczyciel, 0),
            new Prize(self::KNOWN_PRIZE, ShipType::Niszczyciel, ShipVariant::Torpedowy, 0),
        ]);

        $expedition = $this->expedition($client, $profileId);
        $prizes = array_column($expedition['prizes'], 'studyTechnology', 'id');
        self::assertSame([self::PRIZE => 'ciezki-niszczyciel', self::KNOWN_PRIZE => null], $prizes);
        $fleetBefore = count($expedition['fleet']);

        $known = $this->post($client, "/api/profiles/$profileId/prizes/".self::KNOWN_PRIZE, ['decision' => 'study']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('PRIZE_NOT_STUDIABLE', $known['error']['code']);

        $studied = $this->post($client, "/api/profiles/$profileId/prizes/".self::PRIZE, ['decision' => 'study']);
        self::assertResponseIsSuccessful();
        self::assertSame('study', $studied['decision']);
        self::assertSame('ciezki-niszczyciel', $studied['technology']);
        self::assertNull($studied['ship']);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame([self::KNOWN_PRIZE], array_column($expedition['prizes'], 'id'));
        self::assertCount($fleetBefore, $expedition['fleet']);
        $heavy = array_column(array_column($expedition['shipTypes'], null, 'type')['niszczyciel']['variants'], null, 'variant')['ciezki-niszczyciel'];
        self::assertSame(['variant' => 'ciezki-niszczyciel', 'requiredTechnology' => 'ciezki-niszczyciel', 'unlock' => 'boarding', 'unlocked' => true], $heavy);
        $node = array_column($expedition['research']['technologies'], null, 'id')['ciezki-niszczyciel'];
        self::assertSame('researched', $node['state']);
        self::assertSame(['event' => 'prize-decided', 'decision' => 'study', 'technology' => 'ciezki-niszczyciel'], array_intersect_key($expedition['log'][0], ['event' => 1, 'decision' => 1, 'technology' => 1]));

        // drugi raz tej samej zdobyczy nie ma
        $again = $this->post($client, "/api/profiles/$profileId/prizes/".self::PRIZE, ['decision' => 'study']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('PRIZE_NOT_FOUND', $again['error']['code']);
    }

    public function testAcademyDoesNotResearchBoardingTechnologies(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, Sea::START, 'zatoka-rozbitka', owned: ['zatoka-rozbitka'], buildings: ['zatoka-rozbitka' => [BuildingType::Uczelnia]]);

        $nodes = array_column($this->expedition($client, $profileId)['research']['technologies'], null, 'id');
        self::assertSame('available', $nodes['spichlerze']['state']);
        foreach (['kuter-torpedowy', 'ciezki-niszczyciel', 'lotniskowiec-eskortowy'] as $id) {
            self::assertSame(['level' => null, 'unlock' => 'boarding', 'state' => 'locked', 'blocker' => 'boarding-only'], array_intersect_key($nodes[$id], ['level' => 1, 'unlock' => 1, 'state' => 1, 'blocker' => 1]), $id);
            $refused = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/research", ['technology' => $id]);
            self::assertResponseStatusCodeSame(409);
            self::assertSame('TECHNOLOGY_BOARDING_ONLY', $refused['error']['code']);
        }
    }

    public function testEnemyOnStormOceanIslandsCarriesTheIslandsVariant(): void
    {
        $client = static::createClient();
        $graph = static::getContainer()->get(SeaGraph::class);
        $ocean = $graph->sea(StaticSeaGraph::STORM_OCEAN);
        foreach (['wyspa-latarnikow', 'rafa-kotwiczna', 'zatoka-burz'] as $islandId) {
            [$profileId, $seed] = $this->captainWithSeed($client, StaticSeaGraph::STORM_OCEAN, $islandId);
            $variant = $ocean->enemyVariantFor($seed, $islandId);
            self::assertNotNull($variant);

            $game = $this->battle($client, $profileId, $islandId);
            // gracz bez wariantów ze zdobyczy; wróg ma lustro składu i wariant wyspy zamiast zwykłego statku
            self::assertSame($game['allowedShips'], $game['opponentShips']);
            self::assertSame([(string) $variant->type()->length() => [$variant->loadout()->toArray()]], $this->stringKeys($game['opponentVariants']), $islandId);
            self::assertSame([], $game['variants']);
        }
    }

    public function testEnemyOnTheSeaOfMistsFliesTheEscortCarrier(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captainWithSeed($client, StaticSeaGraph::MIST_SEA, 'kotwicowisko-mgiel');

        $game = $this->battle($client, $profileId, 'kotwicowisko-mgiel');
        self::assertSame(['4' => [['airRaid' => 1, 'sonar' => 1]]], $this->stringKeys($game['opponentVariants']));
    }

    public function testShipwreckSeaHasNoEnemyOnlyVariantsAndTheMirrorKeepsStudiedOnes(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captainWithSeed($client, Sea::START, 'mielizny', fleet: [
            new OwnedShip('a1', ShipType::Kuter, variant: ShipVariant::KuterTorpedowy),
            new OwnedShip('a2', ShipType::Niszczyciel),
        ]);

        $game = $this->battle($client, $profileId, 'mielizny');
        self::assertSame(['2' => [['torpedo' => 1]]], $this->stringKeys($game['variants']));
        self::assertSame(['2' => [['torpedo' => 1]]], $this->stringKeys($game['opponentVariants']));
    }

    public function testDefenseOnTheOceanMirrorsOnlyThePlayersVariants(): void
    {
        $client = static::createClient();
        $islandId = 'rafa-kotwiczna';
        [$profileId] = $this->captainWithSeed($client, StaticSeaGraph::STORM_OCEAN, $islandId, owned: true, fleet: [
            new OwnedShip('d1', ShipType::Kuter, variant: ShipVariant::KuterTorpedowy),
            new OwnedShip('d2', ShipType::Kuter),
            new OwnedShip('d3', ShipType::Niszczyciel),
        ]);

        $game = $this->battle($client, $profileId, $islandId);
        self::assertSame(['2' => [['torpedo' => 1]]], $this->stringKeys($game['opponentVariants']));
    }

    public function testStraitGuardianSailsWithBothOceanVariants(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->mapOf(static::getContainer()->get(SeaGraph::class)->sea(Sea::START), $profile);
        $gate = $world->gatePosition(StaticSeaGraph::GUARDED_STRAIT);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 400, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $gate, ["{$gate['x']}:{$gate['y']}"],
        ));

        $game = $this->post($client, "/api/profiles/$profileId/gates/".StaticSeaGraph::GUARDED_STRAIT.'/battle');
        self::assertResponseStatusCodeSame(201);
        $client->request('GET', "/api/games/{$game['id']}");
        $view = json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertSame(['2' => [['torpedo' => 1]], '3' => [['torpedo' => 2]]], $this->stringKeys($view['opponentVariants']));
    }

    /** @return array<string,mixed> widok gry po starcie bitwy o wyspę */
    private function battle(KernelBrowser $client, string $profileId, string $islandId): array
    {
        $game = $this->post($client, "/api/profiles/$profileId/islands/$islandId/battle");
        self::assertResponseStatusCodeSame(201, (string) $client->getResponse()->getContent());
        $client->request('GET', "/api/games/{$game['id']}");
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Klucze długości jako napisy: JSON zamienia mapę {"2": …} na tablicę PHP z kluczem int.
     *
     * @param array<mixed> $variants
     *
     * @return array<string,mixed>
     */
    private function stringKeys(array $variants): array
    {
        $out = [];
        foreach ($variants as $length => $loadouts) {
            $out[(string) $length] = $loadouts;
        }

        return $out;
    }

    /**
     * Admirał przy wyspie akwenu (bramy otwarte) z flotą kutra, niszczyciela
     * i lotniskowca; z owned = wyspa pod banderą i kontratak w toku na nią.
     *
     * @param list<OwnedShip>|null $fleet
     *
     * @return array{0: string, 1: int} [id profilu, seed świata]
     */
    private function captainWithSeed(KernelBrowser $client, string $sea, string $islandId, bool $owned = false, ?array $fleet = null): array
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $here = static::getContainer()->get(WorldFactory::class)->mapOf(static::getContainer()->get(SeaGraph::class)->sea($sea), $profile)->islandPosition($islandId);
        self::assertNotNull($here);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 1300, [],
            $fleet ?? [new OwnedShip('s1', ShipType::Kuter), new OwnedShip('s2', ShipType::Niszczyciel), new OwnedShip('s3', ShipType::Lotniskowiec)],
            Resources::none(), $profile->worldSeed(), $here, ["{$here['x']}:{$here['y']}"],
            tick: 5,
            depositTicks: $owned ? [$islandId => 5] : [],
            ownedIslands: $owned ? [$islandId => 0] : [],
            counterattacks: $owned ? ['pending' => ['island' => $islandId, 'announcedAt' => 4, 'strikeAt' => 10, 'defense' => null], 'calmFrom' => 0, 'rolledTo' => 4, 'last' => null] : null,
            sea: $sea,
            openedGates: [StaticSeaGraph::GUARDED_STRAIT => 0],
        ));

        return [$profileId, $profile->worldSeed()];
    }

    /**
     * Kapitan przy wyspie Morza Rozbitków, z zapasem w jej magazynie.
     *
     * @param list<string>                      $owned
     * @param array<string, list<BuildingType>> $buildings
     * @param list<Prize>                       $prizes
     * @param list<Technology>                  $researched
     */
    private function captain(KernelBrowser $client, string $sea, string $islandId, array $owned = [], array $buildings = [], array $prizes = [], array $researched = []): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $here = static::getContainer()->get(WorldFactory::class)->mapOf(static::getContainer()->get(SeaGraph::class)->sea($sea), $profile)->islandPosition($islandId);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 40, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $here,
            stockpiles: [$islandId => new Resources(100, 100, 100)],
            depositTicks: array_fill_keys($owned, 0),
            ownedIslands: array_fill_keys($owned, 0),
            buildings: $buildings,
            researched: $researched,
            prizes: $prizes,
        ));

        return $profileId;
    }

    /** @return array<string,mixed> */
    private function expedition(KernelBrowser $client, string $profileId): array
    {
        $client->request('GET', "/api/profiles/$profileId/expedition");
        self::assertResponseIsSuccessful();

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    /** @return array<string,mixed> */
    private function post(KernelBrowser $client, string $url, array $payload = []): array
    {
        $client->request('POST', $url, server: ['CONTENT_TYPE' => 'application/json'], content: json_encode($payload, JSON_THROW_ON_ERROR));

        return json_decode((string) $client->getResponse()->getContent() ?: '{}', true) ?? [];
    }
}
