<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class CounterattackApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testDefenseBattleStartsWithAttackerSalvoAndSettlesTheCounterattack(): void
    {
        $client = static::createClient();
        // kontratak zapowiedziany w tiku 20, uderzy w 32; kapitan stoi na wyspie celu w tiku 25
        [$profileId, $kuterId] = $this->captainOnOwnedIsland($client, tick: 25, pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null]);

        $pending = $this->expedition($client, $profileId)['counterattack']['pending'];
        self::assertSame('wyspa-sygnalowa', $pending['island']);
        self::assertSame('Wyspa Sygnałowa', $pending['islandName']);
        self::assertSame(7, $pending['ticksLeft']);
        self::assertFalse($pending['defenseStarted']);
        self::assertFalse($pending['fullFlag']);
        self::assertFalse($pending['fort']);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [$kuterId]])['id'];
        self::assertResponseStatusCodeSame(201);

        // jedna obrona na kontratak
        $again = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [$kuterId]]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('DEFENSE_ALREADY_STARTED', $again['error']['code']);

        // napastnik strzela pierwszy: po rozstawieniu floty AI ma już salwę na koncie
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2]]]);
        self::assertResponseIsSuccessful();
        $client->request('GET', "/api/games/$gameId");
        $game = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
        self::assertCount(1, [...$game['playerUnderFireGrid']['hits'], ...$game['playerUnderFireGrid']['misses']]);
        self::assertSame('player', $game['turn']);
        self::assertSame($game['allowedShips'], $game['opponentShips']);

        // lustrzany kuter wroga stoi na (0,0) poziomo (generator testowy)
        foreach ([[0, 0], [1, 0]] as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
        }
        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();

        // AI strzela losowo — wynik jako niezmiennik: wygrana trzyma banderę, przegrana ją odbiera
        $held = 'won' === $settled['result'];
        self::assertFalse($settled['captured']);
        self::assertSame($held, $settled['defense']['held']);
        self::assertSame($held ? 70 : 15, $settled['awarded']);

        $expedition = $this->expedition($client, $profileId);
        $islands = array_column($expedition['islands'], null, 'id');
        self::assertSame($held, $islands['wyspa-sygnalowa']['owned']);
        self::assertNull($expedition['counterattack']['pending']);
        self::assertSame($held, $expedition['counterattack']['last']['held']);
        self::assertFalse($expedition['counterattack']['last']['absent']);
    }

    public function testFullFlagDefenderFiresFirstAgainstAttackerWithoutItsLargestShip(): void
    {
        $client = static::createClient();
        $islands = array_keys(static::getContainer()->get(WorldFactory::class)->worldFor(CaptainProfile::create('x'))->islands());
        [$profileId, $kuterId, $raftIds] = $this->captainOnOwnedIsland(
            $client, tick: 25, pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null],
            owned: array_fill_keys($islands, 0),
        );

        self::assertTrue($this->expedition($client, $profileId)['counterattack']['pending']['fullFlag']);

        // skład obrońcy: kuter + 2 tratwy; napastnik płynie bez kutra
        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [$kuterId, ...$raftIds]])['id'];
        self::assertResponseStatusCodeSame(201);

        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2], ['x' => 5, 'y' => 7, 'o' => 'h', 'l' => 1], ['x' => 7, 'y' => 7, 'o' => 'h', 'l' => 1],
        ]]);
        self::assertResponseIsSuccessful();
        $client->request('GET', "/api/games/$gameId");
        $game = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // obrońca strzela pierwszy: po rozstawieniu floty AI nie oddało salwy
        self::assertSame([], [...$game['playerUnderFireGrid']['hits'], ...$game['playerUnderFireGrid']['misses']]);
        self::assertSame('player', $game['turn']);
        self::assertSame(['2' => 1, '1' => 2], $game['allowedShips']);
        self::assertSame(['1' => 2], $game['opponentShips']);
        // bez kutra napastnik nie ma sonaru
        self::assertSame(0, $game['opponentWeapons']['sonar']['limit']);

        // tratwy wroga stoją w (0,0) i (2,0) (generator testowy); flota obrońcy
        // ma 4 pola, więc AI nie zdąży jej zatopić między dwoma strzałami
        foreach ([[0, 0], [2, 0]] as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
        }
        $client->request('GET', "/api/games/$gameId");
        self::assertSame('won', json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['status']);

        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertResponseIsSuccessful();
        self::assertTrue($settled['defense']['held']);

        $expedition = $this->expedition($client, $profileId);
        self::assertTrue(array_column($expedition['islands'], null, 'id')['wyspa-sygnalowa']['owned']);
        self::assertNull($expedition['counterattack']['pending']);
    }

    public function testAbsenceUntilTheStrikeLosesTheIslandAndHalfOfItsStockpile(): void
    {
        $client = static::createClient();
        // termin minął (tik 32 > uderzenie w 30), obrony nikt nie rozpoczął
        [$profileId] = $this->captainOnOwnedIsland($client, tick: 32, pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 18, 'strikeAt' => 30, 'defense' => null], stockpile: new Resources(21, 7, 3));

        $expedition = $this->expedition($client, $profileId);
        $islands = array_column($expedition['islands'], null, 'id');

        self::assertFalse($islands['wyspa-sygnalowa']['owned']);
        self::assertSame(['wood' => 11, 'steel' => 4, 'gold' => 2], $islands['wyspa-sygnalowa']['stockpile']);
        self::assertNull($expedition['counterattack']['pending']);
        self::assertSame([
            'island' => 'wyspa-sygnalowa', 'islandName' => 'Wyspa Sygnałowa', 'tick' => 30, 'held' => false, 'absent' => true,
            'plundered' => false, 'stockpileLost' => ['wood' => 10, 'steel' => 3, 'gold' => 1],
        ], $expedition['counterattack']['last']);
        self::assertCount(4, $expedition['fleet']);

        // wyspa znowu jest areną
        $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle");
        self::assertResponseStatusCodeSame(201);
    }

    public function testFortIsTelegraphedInTheWarning(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captainOnOwnedIsland($client, tick: 25, pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 20, 'strikeAt' => 32, 'defense' => null], buildings: ['wyspa-sygnalowa' => [BuildingType::Fort]]);

        self::assertTrue($this->expedition($client, $profileId)['counterattack']['pending']['fort']);
    }

    public function testAbsenceAtIslandWithFortPlundersHalfOfTheStockpileAndKeepsTheFlag(): void
    {
        $client = static::createClient();
        // termin minął (tik 32 > uderzenie w 30), obrony nikt nie rozpoczął, na wyspie stoi fort
        [$profileId] = $this->captainOnOwnedIsland(
            $client, tick: 32, pending: ['island' => 'wyspa-sygnalowa', 'announcedAt' => 18, 'strikeAt' => 30, 'defense' => null],
            stockpile: new Resources(21, 7, 3), buildings: ['wyspa-sygnalowa' => [BuildingType::Fort]],
        );

        $expedition = $this->expedition($client, $profileId);
        $islands = array_column($expedition['islands'], null, 'id');

        self::assertTrue($islands['wyspa-sygnalowa']['owned']);
        self::assertSame(['fort'], $islands['wyspa-sygnalowa']['buildings']);
        self::assertSame(['wood' => 11, 'steel' => 4, 'gold' => 2], $islands['wyspa-sygnalowa']['stockpile']);
        self::assertNull($expedition['counterattack']['pending']);
        self::assertSame([
            'island' => 'wyspa-sygnalowa', 'islandName' => 'Wyspa Sygnałowa', 'tick' => 30, 'held' => false, 'absent' => true,
            'plundered' => true, 'stockpileLost' => ['wood' => 10, 'steel' => 3, 'gold' => 1],
        ], $expedition['counterattack']['last']);
        self::assertCount(4, $expedition['fleet']);

        // wyspa została pod banderą, więc nie jest areną
        $refused = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_COUNTERATTACK', $refused['error']['code']);
    }

    public function testOwnedIslandWithoutCounterattackRefusesBattle(): void
    {
        $client = static::createClient();
        [$profileId] = $this->captainOnOwnedIsland($client, tick: 3, pending: null);

        self::assertNull($this->expedition($client, $profileId)['counterattack']['pending']);
        $refused = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_COUNTERATTACK', $refused['error']['code']);
    }

    /**
     * Kapitan (marynarz, żeby Sygnałowa była dostępna) zakotwiczony na Wyspie
     * Sygnałowej pod własną banderą, z zadanym stanem kontrataku.
     *
     * @param array{island:string, announcedAt:int, strikeAt:int, defense:?string}|null $pending
     * @param array<string, int>|null                                                   $owned     bandery; null = tylko Sygnałowa
     * @param array<string, list<BuildingType>>                                         $buildings budynki osady
     *
     * @return array{0:string, 1:string, 2:list<string>} [id profilu, id kutra, id tratw]
     */
    private function captainOnOwnedIsland(KernelBrowser $client, int $tick, ?array $pending, ?Resources $stockpile = null, ?array $owned = null, array $buildings = []): array
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 80, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('wyspa-sygnalowa'), null, 0, $tick, null,
            null !== $stockpile ? ['wyspa-sygnalowa' => $stockpile] : [], null, [], $owned ?? ['wyspa-sygnalowa' => 0],
            ['pending' => $pending, 'calmFrom' => 0, 'rolledTo' => $tick - 1, 'last' => null], $buildings,
        ));

        return [$profileId, $profile->fleet()[0]->id, [$profile->fleet()[1]->id, $profile->fleet()[2]->id]];
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
