<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\BuildingType;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Technology;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\Encounters;

final class TwoSectorSailingApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testTwoSectorSailingNeedsTheTechnologyAndCostsOneTick(): void
    {
        $client = static::createClient();

        // bez technologii: zasięg 1, rejs o 2 odrzucony
        $plain = $this->captain($client, xp: 250, researched: [Technology::MapySztormowe]);
        $world = $this->expedition($client, $plain)['world'];
        self::assertSame(1, $world['sailRange']);
        $this->post($client, "/api/profiles/$plain/sail", $this->away($world, 2));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SAIL_NOT_ADJACENT', $this->lastErrorCode($client));
        self::assertSame($world['tick'], $this->expedition($client, $plain)['world']['tick']);

        // z technologią: zasięg 2, rejs o 2 za 1 tik
        $sailor = $this->captain($client, xp: 250, researched: [Technology::MapySztormowe, Technology::ZeglugaDwusektorowa]);
        $world = $this->expedition($client, $sailor)['world'];
        self::assertSame(2, $world['sailRange']);
        $target = $this->away($world, 2);
        $sailed = $this->post($client, "/api/profiles/$sailor/sail", $target);
        self::assertResponseIsSuccessful();
        self::assertSame($target, $sailed['position']);
        $after = $this->expedition($client, $sailor)['world'];
        self::assertSame($world['tick'] + 1, $after['tick']);
        self::assertContains("{$target['x']}:{$target['y']}", $after['visited']);
        // rejs mógł trafić na spotkanie (#141), które zatrzymałoby dalszą żeglugę
        Encounters::dismiss(static::getContainer()->get(ProfileRepository::class), $sailor);

        // dalej niż zasięg
        $this->post($client, "/api/profiles/$sailor/sail", $this->away($after, 3));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SAIL_OUT_OF_RANGE', $this->lastErrorCode($client));
    }

    public function testSecondNavigationLevelIsGatedByStormChartsAndRank(): void
    {
        $client = static::createClient();
        $academy = ['zatoka-rozbitka' => [BuildingType::Uczelnia]];

        $novice = $this->captain($client, xp: 40, researched: [], buildings: $academy);
        self::assertSame('prerequisite', $this->blocker($client, $novice));

        $bosman = $this->captain($client, xp: 40, researched: [Technology::MapySztormowe], buildings: $academy);
        self::assertSame('rank', $this->blocker($client, $bosman));
        $this->post($client, "/api/profiles/$bosman/islands/zatoka-rozbitka/research", ['technology' => 'zegluga-dwusektorowa']);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('TECHNOLOGY_LOCKED', $this->lastErrorCode($client));

        $sternik = $this->captain($client, xp: 250, researched: [Technology::MapySztormowe], buildings: $academy);
        self::assertNull($this->blocker($client, $sternik));
        $started = $this->post($client, "/api/profiles/$sternik/islands/zatoka-rozbitka/research", ['technology' => 'zegluga-dwusektorowa']);
        self::assertResponseStatusCodeSame(201);
        self::assertSame(10, $started['ticksLeft']);
    }

    /**
     * Sektor w odległości $distance od pozycji, po prostej w poziomie.
     *
     * @param array<string,mixed> $world
     *
     * @return array{x:int,y:int}
     */
    private function away(array $world, int $distance): array
    {
        $pos = $world['position'];
        $dx = $pos['x'] + $distance < $world['width'] ? 1 : -1;

        return ['x' => $pos['x'] + $distance * $dx, 'y' => $pos['y']];
    }

    /**
     * Kapitan z flotą startową w Zatoce Rozbitka (pod banderą), z magazynem na badania.
     *
     * @param list<Technology>                  $researched
     * @param array<string, list<BuildingType>> $buildings
     */
    private function captain(KernelBrowser $client, int $xp, array $researched, array $buildings = []): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), $xp, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('zatoka-rozbitka'),
            stockpiles: ['zatoka-rozbitka' => new Resources(100, 100, 100)],
            depositTicks: ['zatoka-rozbitka' => 0],
            ownedIslands: ['zatoka-rozbitka' => 0],
            buildings: $buildings,
            researched: $researched,
        ));

        return $profileId;
    }

    private function blocker(KernelBrowser $client, string $profileId): ?string
    {
        $technologies = array_column($this->expedition($client, $profileId)['research']['technologies'], null, 'id');

        return $technologies['zegluga-dwusektorowa']['blocker'];
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
