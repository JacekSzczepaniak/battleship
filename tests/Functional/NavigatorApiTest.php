<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Sea;
use App\Domain\Expedition\Technology;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\Encounters;

/** Nawigator (#153): plan trasy przez API i przepłynięcie jej zwykłymi rejsami. */
final class NavigatorApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testPlanningNeedsTheTechnology(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, [Technology::MapySztormowe]);

        self::assertSame(['available' => false, 'destinations' => []], $this->expedition($client, $profileId)['world']['navigator']);
        $this->route($client, $profileId, ['sea' => Sea::START, 'x' => 0, 'y' => 0]);
        self::assertResponseStatusCodeSame(403);
        self::assertSame('TECHNOLOGY_LOCKED', $this->lastErrorCode($client));
    }

    public function testRouteCostsTheSumOfOrdinarySailsAndCanBeSailedStepByStep(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, [Technology::MapySztormowe, Technology::Nawigator]);
        $world = $this->expedition($client, $profileId)['world'];
        self::assertTrue($world['navigator']['available']);
        self::assertContains('zatoka-rozbitka', array_column($world['navigator']['destinations'], 'id'));

        $target = $this->away($world, 3);
        $plan = $this->route($client, $profileId, ['sea' => Sea::START] + $target);
        self::assertResponseIsSuccessful();
        self::assertNull($plan['reason']);
        self::assertSame(3, $plan['route']['ticks']);
        // poza portem startowym nic nie jest odwiedzone: każdy krok grozi sztormem
        self::assertSame(3, $plan['route']['stormSectors']);
        self::assertSame($world['tick'] + 3, $plan['route']['arrivalTick']);
        self::assertSame([Sea::START => 10], $plan['route']['stormChance']);

        foreach ($plan['route']['steps'] as $step) {
            self::assertSame('sail', $step['type']);
            $this->post($client, "/api/profiles/$profileId/sail", ['x' => $step['x'], 'y' => $step['y']]);
            self::assertResponseIsSuccessful();
            // spotkanie (#141) zatrzymałoby trasę do decyzji; tu sprawdzamy sam koszt
            // repozytorium z bieżącego kontenera: klient przeładowuje kernel między żądaniami
            Encounters::dismiss(static::getContainer()->get(ProfileRepository::class), $profileId);
        }
        $after = $this->expedition($client, $profileId)['world'];
        self::assertSame($target, $after['position']);
        self::assertSame($world['tick'] + $plan['route']['ticks'], $after['tick']);
    }

    public function testRouteReportsWhyThereIsNoRoute(): void
    {
        $client = static::createClient();
        $profileId = $this->captain($client, [Technology::MapySztormowe, Technology::Nawigator], discoverAll: false);
        $world = $this->expedition($client, $profileId)['world'];

        $far = $this->away($world, 5);
        $plan = $this->route($client, $profileId, ['sea' => Sea::START] + $far);
        self::assertSame('undiscovered', $plan['reason']);
        self::assertNull($plan['route']);

        $near = $this->away($world, 1);
        $safe = $this->route($client, $profileId, ['sea' => Sea::START] + $near + ['avoidUnvisited' => '1']);
        self::assertSame('no-safe-route', $safe['reason']);
        self::assertSame(1, $this->route($client, $profileId, ['sea' => Sea::START] + $near)['route']['ticks']);

        $this->route($client, $profileId, ['sea' => 'atlantyda', 'x' => 0, 'y' => 0]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('SEA_NOT_FOUND', $this->lastErrorCode($client));
        $this->route($client, $profileId, ['sea' => Sea::START, 'x' => 'a', 'y' => 0]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testRouteTellsWhetherItArrivesBeforeTheCounterattack(): void
    {
        $client = static::createClient();
        $profileId = $this->captain(
            $client, [Technology::MapySztormowe, Technology::Nawigator],
            pending: ['island' => 'zatoka-rozbitka', 'announcedAt' => 0, 'strikeAt' => 3, 'defense' => null],
        );
        $world = $this->expedition($client, $profileId)['world'];

        $short = $this->route($client, $profileId, ['sea' => Sea::START] + $this->away($world, 2));
        $long = $this->route($client, $profileId, ['sea' => Sea::START] + $this->away($world, 3));

        self::assertSame(['island' => 'zatoka-rozbitka', 'strikeAt' => 3, 'ticksLeft' => 3, 'inTime' => true, 'targetsIsland' => false], $short['counterattack']);
        self::assertFalse($long['counterattack']['inTime']);
    }

    /**
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
     * Kapitan w Zatoce Rozbitka (pod banderą); domyślnie z odkrytą całą mapą akwenu.
     * Odwiedzona jest tylko zatoka.
     *
     * @param list<Technology>                                                          $researched
     * @param array{island:string, announcedAt:int, strikeAt:int, defense:?string}|null $pending
     */
    private function captain(KernelBrowser $client, array $researched, bool $discoverAll = true, ?array $pending = null): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $at = $world->islandPosition('zatoka-rozbitka');
        // cała mapa albo tylko sąsiedztwo zatoki, jak po pierwszym wejściu do gry
        $discovered = [];
        for ($x = 0; $x < WorldMap::WIDTH; ++$x) {
            for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
                if ($discoverAll || max(abs($x - $at['x']), abs($y - $at['y'])) <= 1) {
                    $discovered[] = "$x:$y";
                }
            }
        }
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 250, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $at,
            $discovered,
            tick: 0,
            depositTicks: ['zatoka-rozbitka' => 0],
            ownedIslands: ['zatoka-rozbitka' => 0],
            counterattacks: null !== $pending ? ['pending' => $pending, 'calmFrom' => 0, 'rolledTo' => 0, 'last' => null] : null,
            researched: $researched,
        ));

        return $profileId;
    }

    /**
     * @param array<string, int|string> $query
     *
     * @return array<string,mixed>
     */
    private function route(KernelBrowser $client, string $profileId, array $query): array
    {
        $client->request('GET', "/api/profiles/$profileId/navigator/route?".http_build_query($query));

        return json_decode($client->getResponse()->getContent() ?: '{}', true) ?? [];
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
