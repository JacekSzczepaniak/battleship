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
use App\Infrastructure\Persistence\Doctrine\Mapper\ProfileSnapshotMapper;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Specjalizacje kapitana (#160): widok w /expedition, naliczanie z bitwy, rynku i przeładunku, awans w dzienniku. */
final class SpecializationsApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testNewProfileStartsAtZeroWithPerkCatalog(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $tracks = array_column($this->expedition($client, $profileId)['profile']['specializations'], null, 'track');

        self::assertSame(['combat', 'economy'], array_keys($tracks));
        $combat = $tracks['combat'];
        self::assertSame(0, $combat['points']);
        self::assertSame(0, $combat['level']);
        self::assertSame(5, $combat['maxLevel']);
        self::assertSame(['level' => 1, 'points' => 20, 'pointsNeeded' => 20], $combat['next']);
        self::assertSame(['win', 'enemy-sunk', 'boarding', 'defense', 'siege'], array_column($combat['sources'], 'source'));
        self::assertSame(
            ['repair-discount', 'boarding-edge', 'extra-sonar', 'loot-bonus', 'first-turn-energy'],
            array_column($combat['perks'], 'perk'),
        );
        self::assertSame([false, false, false, false, false], array_column($combat['perks'], 'active'));
        $turnover = array_column($tracks['economy']['sources'], null, 'source')['market-turnover'];
        self::assertSame(['source' => 'market-turnover', 'units' => 0, 'points' => 0, 'unitsPerPoint' => 20, 'pointsPerUnit' => 1], $turnover);
    }

    public function testWonBattleLevelsUpCombatAndLogsIt(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        // 3 wcześniejsze wygrane = 15 pkt; wygrana z 4 zatopionymi (+9) przekracza próg 20
        $this->withState($profileId, static fn (array $state) => ['specializations' => ['win' => 3]] + $state);

        $gameId = $this->post($client, "/api/profiles/$profileId/islands/zatoka-rozbitka/battle")['id'];
        // flota kuter + 3 tratwy, lustrzany wróg na znanych pozycjach (jak ExpeditionApiTest)
        $this->post($client, "/api/games/$gameId/fleet", ['ships' => [
            ['x' => 0, 'y' => 0, 'o' => 'h', 'l' => 2],
            ['x' => 4, 'y' => 0, 'o' => 'h', 'l' => 1],
            ['x' => 6, 'y' => 2, 'o' => 'h', 'l' => 1],
            ['x' => 0, 'y' => 4, 'o' => 'h', 'l' => 1],
        ]]);
        foreach ([[0, 0], [1, 0], [3, 0], [5, 0], [0, 2]] as [$x, $y]) {
            $this->post($client, "/api/games/$gameId/shots", ['x' => $x, 'y' => $y]);
        }
        self::assertSame('won', $this->post($client, "/api/profiles/$profileId/battles/$gameId/settle")['result']);

        $expedition = $this->expedition($client, $profileId);
        $combat = array_column($expedition['profile']['specializations'], null, 'track')['combat'];
        self::assertSame(24, $combat['points']);
        self::assertSame(1, $combat['level']);
        self::assertSame(20, $combat['levelFrom']);
        self::assertSame(['level' => 2, 'points' => 60, 'pointsNeeded' => 36], $combat['next']);
        self::assertTrue($combat['perks'][0]['active']);
        self::assertFalse($combat['perks'][1]['active']);
        $levelUps = array_values(array_filter($expedition['log'], static fn (array $e) => 'specialization-level' === $e['event']));
        self::assertCount(1, $levelUps);
        self::assertSame('combat', $levelUps[0]['track']);
        self::assertSame(1, $levelUps[0]['level']);
    }

    public function testMarketTurnoverAndTransportBetweenIslands(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $port = static::getContainer()->get(WorldFactory::class)->worldFor($profile)->tradePortPosition();
        // w ładowni 40 drewna załadowanego na Zatoce Rozbitka, w magazynie portu drewno na sprzedaż
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [],
            [OwnedShip::build(ShipType::Transportowiec), OwnedShip::build(ShipType::Transportowiec)],
            new Resources(wood: 40),
            $profile->worldSeed(), $port, ["{$port['x']}:{$port['y']}"],
            stockpiles: ['targ-rozbitkow' => new Resources(wood: 300)],
            cargoOrigins: ['zatoka-rozbitka' => 40],
        ));

        // rozładunek w porcie: 40 jednostek z innej wyspy = 2 pkt
        $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'targ-rozbitkow', 'direction' => 'unload', 'wood' => 40]);
        self::assertResponseIsSuccessful();
        // sprzedaż 13 + 13 złota: obrót 26 = 1 pkt, reszta 6 czeka
        foreach ([13, 13] as $gold) {
            $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/trade", ['side' => 'sell', 'good' => 'wood', 'gold' => $gold]);
            self::assertResponseIsSuccessful();
        }
        // załadunek i rozładunek w tym samym porcie to nie transport
        $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'targ-rozbitkow', 'direction' => 'load', 'wood' => 20]);
        $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'targ-rozbitkow', 'direction' => 'unload', 'wood' => 20]);

        $economy = array_column($this->expedition($client, $profileId)['profile']['specializations'], null, 'track')['economy'];
        $sources = array_column($economy['sources'], null, 'source');
        self::assertSame(40, $sources['transport']['units']);
        self::assertSame(2, $sources['transport']['points']);
        self::assertSame(26, $sources['market-turnover']['units']);
        self::assertSame(1, $sources['market-turnover']['points']);
        self::assertSame(3, $economy['points']);
        self::assertSame(0, $economy['level']);
    }

    /** @param callable(array<string,mixed>): array<string,mixed> $change */
    private function withState(string $profileId, callable $change): void
    {
        $repository = static::getContainer()->get(ProfileRepository::class);
        $mapper = new ProfileSnapshotMapper();
        $profile = $repository->get(new ProfileId($profileId)) ?? throw new \LogicException('Profile not found');
        $repository->save($mapper->toDomain($profileId, $change($mapper->toArray($profile))));
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
