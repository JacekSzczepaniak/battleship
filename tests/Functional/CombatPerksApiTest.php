<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\Specializations;
use App\Domain\Expedition\SpecializationSource;
use App\Domain\Shared\ProfileId;
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Perki ścieżki bojowej (#161): wkład w bitwie wyprawy (sonar, energia, abordaż), łup i remont w API. */
final class CombatPerksApiTest extends WebTestCase
{
    private const BATTLE_KUTER = 'd1f1f6f0-2c9c-4a51-9d0e-1f4c1d5e7161';
    private const STORM_KUTER = 'd1f1f6f0-2c9c-4a51-9d0e-1f4c1d5e7162';

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMasterCaptainSeesSonarEnergyAndBoardingPerksInBattle(): void
    {
        $client = static::createClient();
        // 60 wygranych = 300 pkt = poziom 5 ścieżki bojowej
        [$profileId, $kuterId] = $this->captainAtSignalIsland($client, 60);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [$kuterId]])['id'];
        self::assertResponseStatusCodeSame(201);
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2]]]);
        self::assertResponseIsSuccessful();

        $view = $this->get($client, "/api/games/$gameId");
        self::assertSame(['left' => 4, 'max' => 4, 'bonus' => 1], array_intersect_key($view['energy'], ['left' => 0, 'max' => 0, 'bonus' => 0]));
        // jeden kuter = jeden sonar, perk dokłada drugi; wróg (lustro) bez perku
        self::assertSame(['used' => 0, 'limit' => 2, 'radius' => 3, 'bonus' => 1], $view['weapons']['sonar']);
        self::assertSame(['used' => 0, 'limit' => 1, 'radius' => 3], $view['opponentWeapons']['sonar']);
        self::assertSame(4, $view['boarding']['threshold']);
        self::assertSame([['code' => 'captain', 'value' => -1]], $view['boarding']['modifiers']);

        // dwa sonary w pierwszej turze kosztują 2 z 4⚡
        $this->post($client, "/api/games/$gameId/sonar", ['x' => 4, 'y' => 4]);
        self::assertResponseIsSuccessful();
        $ping = $this->post($client, "/api/games/$gameId/sonar", ['x' => 2, 'y' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame(2, $ping['energy']['left']);
        self::assertSame(2, $this->get($client, "/api/games/$gameId")['weapons']['sonar']['used']);
    }

    public function testCaptainBelowPerkLevelsFightsByBaseRules(): void
    {
        $client = static::createClient();
        // 12 wygranych = 60 pkt = poziom 2: tylko abordaż
        [$profileId, $kuterId] = $this->captainAtSignalIsland($client, 12);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/wyspa-sygnalowa/battle", ['shipIds' => [$kuterId]])['id'];
        $view = $this->get($client, "/api/games/$gameId");

        self::assertSame(['left' => 3, 'max' => 3, 'bonus' => 0], array_intersect_key($view['energy'], ['left' => 0, 'max' => 0, 'bonus' => 0]));
        self::assertSame(['used' => 0, 'limit' => 1, 'radius' => 3], $view['weapons']['sonar']);
        self::assertSame(4, $view['boarding']['threshold']);

        // 3 wygrane = 15 pkt, poziom 0: abordaż bez perku
        [$noviceId, $noviceKuterId] = $this->captainAtSignalIsland($client, 3);
        $noviceGameId = $this->post($client, "/api/profiles/$noviceId/islands/wyspa-sygnalowa/battle", ['shipIds' => [$noviceKuterId]])['id'];
        $novice = $this->get($client, "/api/games/$noviceGameId")['boarding'];
        self::assertSame(5, $novice['threshold']);
        self::assertSame([], $novice['modifiers']);
    }

    public function testLootBonusFromLevelFour(): void
    {
        $client = static::createClient();
        // Zatoka: łup 15 drewna i 5 stali; poziom 4 (40 wygranych) daje +20% w górę
        $settled = $this->winAtShipwreckBay($client, 40);
        self::assertSame(['wood' => 18, 'steel' => 6, 'gold' => 0], $settled['loot']);
        self::assertSame(['wood' => 3, 'steel' => 1, 'gold' => 0], $settled['lootBonus']);

        // AI strzela losowo: każdy statek uszkodzony w tej wygranej ma remont ze zniżką
        $fleet = $this->get($client, "/api/profiles/{$settled['profileId']}/expedition")['fleet'];
        foreach (array_filter($fleet, static fn (array $ship) => $ship['damaged']) as $ship) {
            self::assertTrue($ship['repairDiscount']);
            $base = ShipType::from($ship['type'])->repairCost()->toArray();
            self::assertSame(array_map(static fn (int $amount) => intdiv($amount * 80, 100), $base), $ship['repairCost']);
        }

        // poziom 3 (24 wygrane): łup bez premii
        $below = $this->winAtShipwreckBay($client, 24);
        self::assertSame(['wood' => 15, 'steel' => 5, 'gold' => 0], $below['loot']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $below['lootBonus']);
    }

    public function testTrainingBattleHasNoPerks(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $this->withState($profileId, static fn (array $state) => ['specializations' => ['win' => 60]] + $state);

        $gameId = $this->post($client, '/api/games', ['mode' => 'fun'])['id'];
        $view = $this->get($client, "/api/games/$gameId");

        self::assertSame(3, $view['energy']['max']);
        self::assertSame(0, $view['energy']['bonus']);
        self::assertArrayNotHasKey('bonus', $view['weapons']['sonar']);
        self::assertNull($view['boarding']);
    }

    public function testRepairDiscountCoversOnlyBattleDamage(): void
    {
        $client = static::createClient();
        $profileId = $this->captainWithDamagedCutters($client);

        $fleet = array_column($this->get($client, "/api/profiles/$profileId/expedition")['fleet'], null, 'id');
        self::assertSame(['wood' => 6, 'steel' => 0, 'gold' => 0], $fleet[self::BATTLE_KUTER]['repairCost']);
        self::assertTrue($fleet[self::BATTLE_KUTER]['repairDiscount']);
        self::assertSame(['wood' => 8, 'steel' => 0, 'gold' => 0], $fleet[self::STORM_KUTER]['repairCost']);
        self::assertFalse($fleet[self::STORM_KUTER]['repairDiscount']);

        $body = $this->post($client, "/api/profiles/$profileId/fleet/repair", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 14, 'steel' => 0, 'gold' => 0], $body['paid']);
        self::assertSame(['wood' => 6, 'steel' => 0, 'gold' => 0], $body['stockpile']);
    }

    /** Kapitan na poziomie 1 w Zatoce: kuter uszkodzony w wygranej bitwie i kuter uszkodzony w sztormie. */
    private function captainWithDamagedCutters(KernelBrowser $client): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [],
            [
                new OwnedShip(self::BATTLE_KUTER, ShipType::Kuter, damaged: true, battleDamage: true),
                new OwnedShip(self::STORM_KUTER, ShipType::Kuter, damaged: true),
            ],
            Resources::none(), $profile->worldSeed(), $world->islandPosition('zatoka-rozbitka'),
            stockpiles: ['zatoka-rozbitka' => new Resources(wood: 20)],
            specializations: (new Specializations())->add(SpecializationSource::Win, 4),
        ));

        return $profileId;
    }

    /**
     * Wygrana bitwa o Zatokę (tryb klasyczny) kapitana z danym licznikiem wygranych:
     * flota kuter + 3 tratwy, lustrzany wróg na znanych pozycjach (jak SpecializationsApiTest).
     *
     * @return array<string,mixed> odpowiedź rozliczenia z dopisanym profileId
     */
    private function winAtShipwreckBay(KernelBrowser $client, int $wins): array
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $this->withState($profileId, static fn (array $state) => ['specializations' => ['win' => $wins]] + $state);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle")['id'];
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 2],
            ['x' => 4, 'y' => 0, 'o' => 'h', 'l' => 1],
            ['x' => 6, 'y' => 2, 'o' => 'h', 'l' => 1],
            ['x' => 0, 'y' => 4, 'o' => 'h', 'l' => 1],
        ]]);
        foreach ([[0, 0], [1, 0], [3, 0], [5, 0], [0, 2]] as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
        }
        $settled = $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle");
        self::assertSame('won', $settled['result']);

        return $settled + ['profileId' => $profileId];
    }

    /**
     * Kapitan (marynarz) z flotą startową przy Wyspie Sygnałowej (tryb fun, plansza 9×9).
     *
     * @return array{0: string, 1: string} id profilu i kutra
     */
    private function captainAtSignalIsland(KernelBrowser $client, int $wins): array
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 80, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('wyspa-sygnalowa'),
            specializations: (new Specializations())->add(SpecializationSource::Win, $wins),
        ));

        return [$profileId, $profile->fleet()[0]->id];
    }

    private function withState(string $profileId, callable $change): void
    {
        $repository = static::getContainer()->get(ProfileRepository::class);
        $mapper = new ProfileSnapshotMapper();
        $profile = $repository->get(new ProfileId($profileId)) ?? throw new \LogicException('Profile not found');
        $repository->save($mapper->toDomain($profileId, $change($mapper->toArray($profile))));
    }

    /** @return array<string,mixed> */
    private function get(KernelBrowser $client, string $url): array
    {
        $client->request('GET', $url);
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
