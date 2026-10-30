<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\Technology;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Gałąź Oblężnictwo (#177): Lunety artyleryjskie namierzają działo fortu,
 * Lonty prochowe powiększają wybuch magazynu do 5×5. Działają tylko
 * w oblężeniu wyprawy.
 */
final class SiegeTechApiTest extends WebTestCase
{
    private const FLEET = [
        ['x' => 5, 'y' => 5, 'o' => 'h', 'l' => 2],
        ['x' => 0, 'y' => 9, 'o' => 'h', 'l' => 1],
        ['x' => 9, 'y' => 0, 'o' => 'h', 'l' => 1],
        ['x' => 9, 'y' => 9, 'o' => 'h', 'l' => 1],
    ];

    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testSiegeWithSiegeTechnologies(): void
    {
        $client = static::createClient();
        $profileId = $this->besieger($client, [Technology::LunetyArtyleryjskie, Technology::LontyProchowe]);

        $siege = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/siege");
        self::assertResponseStatusCodeSame(201);
        $this->post($client, "/api/games/{$siege['id']}/fleet", ['ships' => self::FLEET]);
        self::assertResponseIsSuccessful();

        $game = static::getContainer()->get(GameRepository::class)->get(new GameId($siege['id']));
        $fort = $game->fort();
        self::assertNotNull($fort);
        $spotted = $fort->spottedGun();

        // namierzone działo widać od startu, ale nie jest trafione
        $view = $this->game($client, $siege['id']);
        self::assertSame([['x' => $spotted->x, 'y' => $spotted->y]], $view['fort']['spotted']);
        self::assertSame(2, $view['fort']['blastRadius']);
        self::assertSame([], $view['fort']['revealed']);
        self::assertSame(['left' => 4, 'total' => 4], $view['fort']['guns']);

        // wybuch magazynu: 5×5 przycięte do planszy
        $this->post($client, "/api/games/{$siege['id']}/shots", ['x' => $fort->magazine->x, 'y' => $fort->magazine->y]);
        $view = $this->game($client, $siege['id']);
        $expected = array_map(static fn ($c) => [$c->x, $c->y], $fort->blastCells(10, 10, 2));
        self::assertSame($expected, $view['fort']['explosion']);
        $span = static fn (int $v) => min(9, $v + 2) - max(0, $v - 2) + 1;
        self::assertCount($span($fort->magazine->x) * $span($fort->magazine->y), $view['fort']['explosion']);
    }

    public function testSiegeWithoutSiegeTechnologies(): void
    {
        $client = static::createClient();
        $profileId = $this->besieger($client, []);

        $siege = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/siege");
        self::assertResponseStatusCodeSame(201);
        $this->post($client, "/api/games/{$siege['id']}/fleet", ['ships' => self::FLEET]);

        $view = $this->game($client, $siege['id']);
        self::assertSame([], $view['fort']['spotted']);
        self::assertSame(1, $view['fort']['blastRadius']);
    }

    public function testSiegeTechnologiesDoNothingOutsideSiege(): void
    {
        $client = static::createClient();
        $profileId = $this->besieger($client, [Technology::LunetyArtyleryjskie, Technology::LontyProchowe], besieged: false);

        // potyczka z flotą strażniczą twierdzy: zwykła bitwa, bez fortu
        $battle = $this->post($client, "/api/profiles/$profileId/islands/twierdza-admiralicji/battle");
        self::assertResponseStatusCodeSame(201);
        $game = static::getContainer()->get(GameRepository::class)->get(new GameId($battle['id']));
        self::assertNull($game->fort());
        self::assertNull($game->spottedFortGun());
        self::assertSame(1, $game->fortBlastRadius());
    }

    /**
     * Kapitan przy Twierdzy Admiralicji z technologiami; domyślnie po wygranej
     * potyczce (wyspa oblężona), więc może od razu zacząć oblężenie.
     *
     * @param list<Technology> $researched
     */
    private function besieger(KernelBrowser $client, array $researched, bool $besieged = true): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 600, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $world->islandPosition('twierdza-admiralicji'),
            researched: $researched,
            besieged: $besieged ? ['twierdza-admiralicji' => 0] : [],
        ));

        return $profileId;
    }

    /** @return array<string,mixed> */
    private function game(KernelBrowser $client, string $gameId): array
    {
        $client->request('GET', "/api/games/$gameId");
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
