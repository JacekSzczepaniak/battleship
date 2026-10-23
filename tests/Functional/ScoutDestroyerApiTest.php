<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\ShipVariant;
use App\Domain\Expedition\Technology;
use App\Domain\Game\Coordinate;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Niszczyciel zwiadowczy (#99): wybór wariantu w stoczni, 2 sonary w bitwie, lustro wroga i straty. */
final class ScoutDestroyerApiTest extends WebTestCase
{
    private const SCOUT_ID = 'd2a7c4e1-5b3f-4c8a-9e6d-1f0a2b3c4d01';
    private const TORPEDO_ID = 'd2a7c4e1-5b3f-4c8a-9e6d-1f0a2b3c4d02';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testShipyardBuildsScoutOnlyAfterResearch(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtSignalIsland($client);

        $types = array_column($this->expedition($client, $profileId)['shipTypes'], null, 'type');
        self::assertSame([
            ['variant' => 'torpedowy', 'requiredTechnology' => null, 'unlock' => null, 'unlocked' => true],
            ['variant' => 'zwiadowczy', 'requiredTechnology' => 'niszczyciel-zwiadowczy', 'unlock' => 'academy', 'unlocked' => false],
            ['variant' => 'ciezki-niszczyciel', 'requiredTechnology' => 'ciezki-niszczyciel', 'unlock' => 'boarding', 'unlocked' => false],
        ], $types['niszczyciel']['variants']);
        self::assertSame('torpedowy', $types['niszczyciel']['defaultVariant']);
        self::assertNull($types['kuter']['defaultVariant']);
        self::assertSame(['kuter-torpedowy'], array_column($types['kuter']['variants'], 'variant'));

        $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'niszczyciel', 'islandId' => 'wyspa-sygnalowa', 'variant' => 'zwiadowczy']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('SHIP_VARIANT_LOCKED', $this->lastErrorCode($client));

        foreach ([['type' => 'niszczyciel', 'variant' => 'laser'], ['type' => 'kuter', 'variant' => 'zwiadowczy']] as $payload) {
            $this->post($client, "/api/profiles/$profileId/ships", $payload + ['islandId' => 'wyspa-sygnalowa']);
            self::assertResponseStatusCodeSame(400);
            self::assertSame('VALIDATION_ERROR', $this->lastErrorCode($client));
        }

        $torpedo = $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'niszczyciel', 'islandId' => 'wyspa-sygnalowa']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('torpedowy', $torpedo['variant']);

        $profileId = $this->captainAtSignalIsland($client, [Technology::SonarDalekiegoZasiegu, Technology::NiszczycielZwiadowczy]);
        $scout = $this->post($client, "/api/profiles/$profileId/ships", ['type' => 'niszczyciel', 'islandId' => 'wyspa-sygnalowa', 'variant' => 'zwiadowczy']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame('zwiadowczy', $scout['variant']);

        $expedition = $this->expedition($client, $profileId);
        $fleet = array_column($expedition['fleet'], null, 'id');
        self::assertSame('zwiadowczy', $fleet[$scout['id']]['variant']);
        self::assertSame(['wood' => 10, 'steel' => 10, 'gold' => 0], $fleet[$scout['id']]['repairCost']);
        // koszt budowy niszczyciela: 30 drewna, 20 stali
        $signal = array_column($expedition['islands'], null, 'id')['wyspa-sygnalowa'];
        self::assertSame(['wood' => 70, 'steel' => 80, 'gold' => 0], $signal['stockpile']);
        self::assertTrue(array_column($expedition['shipTypes'], null, 'type')['niszczyciel']['variants'][1]['unlocked']);
    }

    public function testScoutGivesTwoSonarsAndNoTorpedoToPlayerAndMirror(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtSignalIsland($client, [Technology::SonarDalekiegoZasiegu, Technology::NiszczycielZwiadowczy], $this->destroyers());

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [self::SCOUT_ID]])['id'];
        self::assertResponseStatusCodeSame(201);

        $game = $this->game($client, $gameId);
        // sonar zwiadowcy ma promień gracza (#97); lustro przenosi wariant, ale nie technologię (D6)
        self::assertSame(['used' => 0, 'limit' => 2, 'radius' => 4], $game['weapons']['sonar']);
        self::assertSame(0, $game['weapons']['torpedo']['limit']);
        self::assertSame(['used' => 0, 'limit' => 2, 'radius' => 3], $game['opponentWeapons']['sonar']);
        self::assertSame(0, $game['opponentWeapons']['torpedo']['limit']);
        self::assertSame(['3' => [['sonar' => 2]]], $game['variants']);
        self::assertSame(['3' => [['sonar' => 2]]], $game['opponentVariants']);

        // statek bez wyposażenia wariantu nie zgadza się ze składem
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 3]]]);
        self::assertResponseStatusCodeSame(422);
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 3, 'w' => ['sonar' => 2]]]]);
        self::assertResponseIsSuccessful();
        self::assertSame([['x' => 0, 'y' => 0, 'o' => 'H', 'l' => 3, 'w' => ['sonar' => 2]]], $this->game($client, $gameId)['playerFleet']);

        foreach ([[4, 4], [6, 6]] as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/sonar", ['x' => $x, 'y' => $y]);
            self::assertResponseIsSuccessful();
        }
        self::assertSame(['used' => 2, 'limit' => 2, 'radius' => 4], $this->game($client, $gameId)['weapons']['sonar']);
    }

    public function testSunkScoutTakesItsSonarsAndIsTheShipDamaged(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtSignalIsland($client, [Technology::SonarDalekiegoZasiegu, Technology::NiszczycielZwiadowczy], $this->destroyers());

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [self::TORPEDO_ID, self::SCOUT_ID]])['id'];
        self::assertResponseStatusCodeSame(201);
        $game = $this->game($client, $gameId);
        self::assertSame(2, $game['weapons']['sonar']['limit']);
        self::assertSame(1, $game['weapons']['torpedo']['limit']);

        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 3, 'w' => ['sonar' => 2]],
            ['x' => 0, 'y' => 2, 'o' => 'h', 'l' => 3],
        ]]);
        self::assertResponseIsSuccessful();
        $this->post($client, "/api/games/$gameId/sonar", ['x' => 4, 'y' => 4]);
        self::assertResponseIsSuccessful();

        // wróg zatapia zwiadowcę — jedynego nośnika sonaru: zostało użycie, ale sonar jest niedostępny
        $games = static::getContainer()->get(GameRepository::class);
        $battle = $games->get(new GameId($gameId));
        foreach ([[0, 0], [1, 0], [2, 0]] as [$x, $y]) {
            $battle->fireOpponentShot(new Coordinate($x, $y));
        }
        $games->save($battle);

        $this->post($client, "/api/games/$gameId/sonar", ['x' => 4, 'y' => 4]);
        self::assertResponseStatusCodeSame(422);
        self::assertSame('WEAPON_CARRIER_SUNK', $this->lastErrorCode($client));

        // gracz kończy bitwę wygraną: stratę ponosi zwiadowca, nie niszczyciel torpedowy
        $battle = $games->get(new GameId($gameId));
        foreach ($battle->opponentFleet() ?? [] as $ship) {
            foreach ($ship->cells() as $cell) {
                $battle->fireShot($cell);
            }
        }
        $games->save($battle);

        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();
        self::assertSame('won', $settled['result']);
        self::assertSame(['niszczyciel'], $settled['damagedShips']);

        $fleet = array_column($this->expedition($client, $profileId)['fleet'], null, 'id');
        self::assertTrue($fleet[self::SCOUT_ID]['damaged']);
        self::assertFalse($fleet[self::TORPEDO_ID]['damaged']);
    }

    /** @return list<OwnedShip> niszczyciel torpedowy i zwiadowczy */
    private function destroyers(): array
    {
        return [
            new OwnedShip(self::TORPEDO_ID, ShipType::Niszczyciel),
            new OwnedShip(self::SCOUT_ID, ShipType::Niszczyciel, variant: ShipVariant::Zwiadowczy),
        ];
    }

    /**
     * Kapitan (ranga marynarz) przy Wyspie Sygnałowej (tryb fun, plansza 9×9,
     * stocznia poziomu 3) z magazynem na budowę.
     *
     * @param list<Technology>     $researched
     * @param list<OwnedShip>|null $fleet      null = flota startowa
     */
    private function captainAtSignalIsland(KernelBrowser $client, array $researched = [], ?array $fleet = null): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 80, [], $fleet ?? $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('wyspa-sygnalowa'),
            stockpiles: ['wyspa-sygnalowa' => new Resources(wood: 100, steel: 100)],
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

    /** @return array<string,mixed> */
    private function game(KernelBrowser $client, string $gameId): array
    {
        $client->request('GET', "/api/games/$gameId");
        self::assertResponseIsSuccessful();

        return json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }

    private function lastErrorCode(KernelBrowser $client): ?string
    {
        $body = json_decode($client->getResponse()->getContent() ?: '{}', true);

        return $body['error']['code'] ?? null;
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
