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
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Port handlowy na neutralnej wyspie akwenu i rynek (#140). */
final class TradePortApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testMarketBuysSellsAndSellsShipsAtTradePort(): void
    {
        $client = static::createClient();
        $kuter = OwnedShip::build(ShipType::Kuter);
        $niszczyciel = OwnedShip::build(ShipType::Niszczyciel);
        $profileId = $this->profileAtPort($client, [$kuter, $niszczyciel], new Resources(wood: 20, gold: 4), new Resources(gold: 1));

        $expedition = $this->expedition($client, $profileId);
        $port = $expedition['world']['tradePort'];
        self::assertSame('targ-rozbitkow', $port['id']);
        self::assertSame('Targ Rozbitków', $port['name']);
        self::assertTrue($port['discovered']);
        self::assertTrue($port['present']);
        self::assertSame(['wood' => ['buy' => 5, 'sell' => 10], 'steel' => ['buy' => 3, 'sell' => 6]], $port['rate']);
        self::assertSame(100, $port['pricePercent']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 1], $port['stockpile']);
        // neutralna wyspa nie jest wyspą trasy: nie ma jej na liście wysp ani pod sektorem floty
        self::assertNotContains('targ-rozbitkow', array_column($expedition['islands'], 'id'));
        self::assertNull($expedition['world']['atIsland']);
        $fleet = array_column($expedition['fleet'], null, 'id');
        self::assertSame(3, $fleet[$niszczyciel->id]['saleValue']);

        // kupno: 2 złota (1 z magazynu, 1 z ładowni) → 6 stali do ładowni
        $bought = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/trade", ['side' => 'buy', 'good' => 'steel', 'gold' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 2], $bought['paid']);
        self::assertSame(['wood' => 0, 'steel' => 6, 'gold' => 0], $bought['received']);
        self::assertSame(['wood' => 20, 'steel' => 6, 'gold' => 3], $bought['cargo']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 0], $bought['stockpile']);
        $tick = $bought['tick'];

        // sprzedaż po połowie ceny: 20 drewna → 2 złota
        $sold = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/trade", ['side' => 'sell', 'good' => 'wood', 'gold' => 2]);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 0, 'steel' => 6, 'gold' => 5], $sold['cargo']);
        self::assertSame($tick + 1, $sold['tick']);

        // magazyn portu działa jak każdy magazyn wyspy
        $unloaded = $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'targ-rozbitkow', 'direction' => 'unload', 'steel' => 6]);
        self::assertResponseIsSuccessful();
        self::assertSame(['wood' => 0, 'steel' => 6, 'gold' => 0], $unloaded['stockpile']);

        // sprzedaż statku bez stoczni: niszczyciel 15/10 → 3 złota
        $shipSold = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/ships/{$niszczyciel->id}/sell");
        self::assertResponseIsSuccessful();
        self::assertSame(3, $shipSold['gold']);
        self::assertSame(['wood' => 0, 'steel' => 0, 'gold' => 8], $shipSold['cargo']);
        self::assertSame(15, $shipSold['cargoCapacity']);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame([$kuter->id], array_column($expedition['fleet'], 'id'));
        $last = $expedition['log'][0];
        self::assertSame('ship-sold', $last['event']);
        self::assertSame(3, $last['gold']);
        self::assertSame('Targ Rozbitków', $last['islandName']);
        self::assertSame('market-trade', $expedition['log'][1]['event']);
        self::assertSame('sell', $expedition['log'][1]['side']);
        self::assertSame('Targ Rozbitków', $expedition['log'][1]['islandName']);

        // ostatniej sprawnej jednostki nie sprzedasz
        $error = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/ships/{$kuter->id}/sell");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('LAST_SEAWORTHY_SHIP', $error['error']['code']);
    }

    public function testRejectsTradesWithoutFundsOrCargoRoomAndAwayFromPort(): void
    {
        $client = static::createClient();
        $kuter = OwnedShip::build(ShipType::Kuter);
        $niszczyciel = OwnedShip::build(ShipType::Niszczyciel);
        // ładownia 35, w niej 33
        $profileId = $this->profileAtPort($client, [$kuter, $niszczyciel], new Resources(wood: 30, gold: 3));
        $url = "/api/profiles/$profileId/trade-ports/targ-rozbitkow/trade";

        $error = $this->post($client, $url, ['side' => 'buy', 'good' => 'wood', 'gold' => 4]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_ENOUGH_RESOURCES', $error['error']['code']);

        // 1 złoto z ładowni → 5 drewna: 33 − 1 + 5 = 37 > 35
        $error = $this->post($client, $url, ['side' => 'buy', 'good' => 'wood', 'gold' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('CARGO_FULL', $error['error']['code']);

        // bez niszczyciela ładownia 15 nie pomieści 33 + 3 złota
        $error = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/ships/{$niszczyciel->id}/sell");
        self::assertResponseStatusCodeSame(409);
        self::assertSame('CARGO_FULL', $error['error']['code']);

        self::assertSame(['wood' => 30, 'steel' => 0, 'gold' => 3], $this->expedition($client, $profileId)['profile']['cargo']);

        foreach ([['side' => 'swap', 'good' => 'wood', 'gold' => 1], ['side' => 'buy', 'good' => 'gold', 'gold' => 1], ['side' => 'buy', 'good' => 'wood', 'gold' => 0], ['side' => 'buy', 'good' => 'wood']] as $payload) {
            $this->post($client, $url, $payload);
            self::assertResponseStatusCodeSame(400);
        }

        // port innego akwenu
        $error = $this->post($client, "/api/profiles/$profileId/trade-ports/faktoria-kotwic/trade", ['side' => 'buy', 'good' => 'wood', 'gold' => 1]);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('TRADE_PORT_NOT_FOUND', $error['error']['code']);

        // flota przy Zatoce, nie w porcie
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $error = $this->post($client, "/api/profiles/$profileId/trade-ports/targ-rozbitkow/trade", ['side' => 'buy', 'good' => 'wood', 'gold' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_TRADE_PORT', $error['error']['code']);
        $error = $this->post($client, "/api/profiles/$profileId/cargo", ['islandId' => 'targ-rozbitkow', 'direction' => 'load', 'wood' => 1]);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('NOT_AT_TRADE_PORT', $error['error']['code']);
    }

    public function testTradePortIsNeitherArenaNorCounterattackTarget(): void
    {
        $client = static::createClient();
        $profileId = $this->profileAtPort($client, [OwnedShip::build(ShipType::Kuter), OwnedShip::build(ShipType::Niszczyciel)], Resources::none());

        $error = $this->post($client, "/api/profiles/$profileId/islands/targ-rozbitkow/battle");
        self::assertResponseStatusCodeSame(404);
        self::assertSame('ISLAND_NOT_FOUND', $error['error']['code']);
        $error = $this->post($client, "/api/profiles/$profileId/islands/targ-rozbitkow/siege");
        self::assertResponseStatusCodeSame(404);

        $expedition = $this->expedition($client, $profileId);
        self::assertSame(0, $expedition['profile']['ownedIslands']);
        // bandery na akwenie liczą tylko wyspy trasy
        self::assertSame(6, $expedition['world']['flags'][0]['total']);
    }

    /**
     * Nowy profil z podaną flotą, zakotwiczony w porcie handlowym Morza Rozbitków.
     *
     * @param list<OwnedShip> $fleet
     */
    private function profileAtPort(KernelBrowser $client, array $fleet, Resources $cargo, ?Resources $stock = null): string
    {
        $profileId = $this->post($client, '/api/profiles', ['name' => 'Jacek'])['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $port = static::getContainer()->get(WorldFactory::class)->worldFor($profile)->tradePortPosition();
        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 0, [], $fleet, $cargo,
            $profile->worldSeed(), $port, ["{$port['x']}:{$port['y']}"],
            stockpiles: null !== $stock ? ['targ-rozbitkow' => $stock] : [],
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
