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
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Złomowanie statku w stoczni portu za część kosztu (#124). */
final class ScrapShipApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testScrapsShipsInShipyardPortAndRefundsToIslandStockpile(): void
    {
        $client = static::createClient();
        $kuter = OwnedShip::build(ShipType::Kuter);
        $wrak = OwnedShip::build(ShipType::Lotniskowiec);
        $wrak->markDamaged();
        $zwiadowca = OwnedShip::build(ShipType::Niszczyciel, ShipVariant::Zwiadowczy);
        $tratwa = OwnedShip::build(ShipType::Tratwa);
        $profileId = $this->profileAt($client, 'zatoka-rozbitka', [$kuter, $wrak, $zwiadowca, $tratwa]);

        // widok floty zna zwrot i blokadę przed akcją
        $fleet = array_column($this->expedition($client, $profileId)['fleet'], null, 'id');
        self::assertSame(['wood' => 10, 'steel' => 5, 'gold' => 0], $fleet[$wrak->id]['scrapValue']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $fleet[$tratwa->id]['scrapValue']);
        self::assertNull($fleet[$kuter->id]['scrapBlock']);

        $scrapped = $this->post($client, "/api/profiles/$profileId/ships/{$wrak->id}/scrap", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 10, 'steel' => 5, 'gold' => 0], $scrapped['refund']);
        self::assertSame(['wood' => 10, 'steel' => 5, 'gold' => 0], $scrapped['stockpile']);

        $scrapped = $this->post($client, "/api/profiles/$profileId/ships/{$zwiadowca->id}/scrap", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 15, 'steel' => 10, 'gold' => 0], $scrapped['refund']);

        $scrapped = $this->post($client, "/api/profiles/$profileId/ships/{$tratwa->id}/scrap", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $scrapped['refund']);
        self::assertSame(['wood' => 25, 'steel' => 15, 'gold' => 0], $scrapped['stockpile']);

        // został sam kuter: ostatnia sprawna jednostka
        $expedition = $this->expedition($client, $profileId);
        self::assertSame([$kuter->id], array_column($expedition['fleet'], 'id'));
        self::assertSame('last-seaworthy', $expedition['fleet'][0]['scrapBlock']);
        $error = $this->post($client, "/api/profiles/$profileId/ships/{$kuter->id}/scrap", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('LAST_SEAWORTHY_SHIP', $error['error']['code']);

        // dziennik w API: najnowszy wpis pierwszy
        $last = $expedition['log'][0];
        self::assertSame('ship-scrapped', $last['event']);
        self::assertSame('tratwa', $last['ship']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $last['refund']);
        self::assertSame('Zatoka Rozbitka', $last['islandName']);
        self::assertSame(['ship-scrapped', 'ship-scrapped', 'ship-scrapped'], array_column(array_slice($expedition['log'], 0, 3), 'event'));
        self::assertTrue($expedition['log'][2]['damaged']);
    }

    public function testRejectsScrapAwayFromShipyardAndOfShipInUnsettledBattle(): void
    {
        $client = static::createClient();
        $kuter = OwnedShip::build(ShipType::Kuter);
        $niszczyciel = OwnedShip::build(ShipType::Niszczyciel);

        // Mielizny nie mają stoczni
        $profileId = $this->profileAt($client, 'mielizny', [$kuter, $niszczyciel]);
        $error = $this->post($client, "/api/profiles/$profileId/ships/{$kuter->id}/scrap", ['islandId' => 'mielizny']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NO_SHIPYARD', $error['error']['code']);

        // flota nie stoi przy Zatoce
        $error = $this->post($client, "/api/profiles/$profileId/ships/{$kuter->id}/scrap", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_ISLAND', $error['error']['code']);

        // kuter wypłynął do bitwy, która nie została rozliczona
        $profileId = $this->profileAt($client, 'zatoka-rozbitka', [$kuter, $niszczyciel], [
            'g1' => ['island' => 'mielizny', 'settled' => false, 'result' => null, 'kind' => 'capture', 'ships' => [$kuter->id]],
        ]);
        $error = $this->post($client, "/api/profiles/$profileId/ships/{$kuter->id}/scrap", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('SHIP_IN_BATTLE', $error['error']['code']);

        $error = $this->post($client, "/api/profiles/$profileId/ships/nope/scrap", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('SHIP_NOT_FOUND', $error['error']['code']);

        $this->post($client, "/api/profiles/$profileId/ships/{$kuter->id}/scrap", []);
        self::assertResponseStatusCodeSame(400);
    }

    /**
     * Nowy profil z podaną flotą, zakotwiczony przy wyspie.
     *
     * @param list<OwnedShip>                     $fleet
     * @param array<string, array<string, mixed>> $battles
     */
    private function profileAt(KernelBrowser $client, string $islandId, array $fleet, array $battles = []): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->worldFor($profile);
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, $battles, $fleet, Resources::none(),
            $profile->worldSeed(), $world->islandPosition($islandId),
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
