<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\WorldMap;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\Encounters;

/** Uszkodzony statek zachowuje ładownię (#92): pływa i wozi, nie walczy. */
final class DamagedShipCargoApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testDamagedCuttersHaulResourcesToShipyardAndRepairThere(): void
    {
        $client = static::createClient();
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];

        // dwa uszkodzone kutry na Mieliznach, drewno w magazynie wyspy, stocznia w Zatoce pusta;
        // cała mapa odwiedzona, więc rejs nie trafi w sztorm
        $kutry = [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Kuter)];
        foreach ($kutry as $kuter) {
            $kuter->markDamaged();
        }
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $sectors = [];
        for ($x = 0; $x < WorldMap::WIDTH; ++$x) {
            for ($y = 0; $y < WorldMap::HEIGHT; ++$y) {
                $sectors[] = "$x:$y";
            }
        }
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [], $kutry, Resources::none(),
            $profile->worldSeed(), $world->islandPosition('mielizny'),
            discovered: $sectors, visited: $sectors, stockpiles: ['mielizny' => new Resources(wood: 30)],
        ));

        // uszkodzone kutry wożą pełne 2 × 15
        self::assertSame(30, $this->expedition($client, $profileId)['profile']['cargoCapacity']);
        $loaded = $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'mielizny', 'direction' => 'load', 'wood' => 30]);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 30, 'steel' => 0, 'gold' => 0], $loaded['cargo']);

        // rejs do stoczni w Zatoce Rozbitka — żegluga nie wymaga sprawnej floty; spotkanie (#141) zdejmujemy
        $position = $world->islandPosition('mielizny');
        $shipyard = $world->islandPosition('zatoka-rozbitka');
        while ($position !== $shipyard) {
            $position = [
                'x' => $position['x'] + ($shipyard['x'] <=> $position['x']),
                'y' => $position['y'] + ($shipyard['y'] <=> $position['y']),
            ];
            $this->post($client, "/api/profiles/$profileId/sail", $position);
            self::assertResponseIsSuccessful();
            Encounters::dismiss(static::getContainer()->get(ProfileRepository::class), $profileId);
        }

        // remont płaci ładownia (magazyn Zatoki pusty): 2 × 8 drewna
        foreach ($kutry as $kuter) {
            $this->post($client, "/api/profiles/$profileId/ships/{$kuter->id}/repair", ['islandId' => 'zatoka-rozbitka']);
            self::assertResponseIsSuccessful();
        }

        $expedition = $this->expedition($client, $profileId);
        self::assertSame([false, false], array_column($expedition['fleet'], 'damaged'));
        self::assertSame(['wood' => 14, 'steel' => 0, 'gold' => 0], $expedition['profile']['cargo']);
        self::assertSame(30, $expedition['profile']['cargoCapacity']);
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
