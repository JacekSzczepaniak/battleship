<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/** Zestawy floty (#125): zapis, nadpisanie, limit, zmiana nazwy, usuwanie i widok wyprawy. */
final class FleetPresetApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testSavedPresetIsListedInExpeditionAndOverwrittenBySameName(): void
    {
        $client = static::createClient();
        $profileId = $this->request($client, 'POST', '/api/profiles', ['name' => 'Jacek'])['id'];
        $ids = $this->fleetIdsByType($client, $profileId);

        $created = $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $this->preset('Klin', $ids));
        self::assertResponseStatusCodeSame(201);
        self::assertSame(['w' => 7, 'h' => 7], $created['board']);
        self::assertSame(['kuter', 'tratwa'], array_column($created['ships'], 'type'));

        $overwritten = $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", ['board' => ['w' => 9, 'h' => 9]] + $this->preset('klin', $ids));
        self::assertResponseStatusCodeSame(200);
        self::assertSame($created['id'], $overwritten['id']);

        $expedition = $this->request($client, 'GET', "/api/profiles/$profileId/expedition");
        self::assertCount(1, $expedition['fleetPresets']);
        self::assertSame(['w' => 9, 'h' => 9], $expedition['fleetPresets'][0]['board']);
    }

    public function testSixthPresetIsRejected(): void
    {
        $client = static::createClient();
        $profileId = $this->request($client, 'POST', '/api/profiles', ['name' => 'Jacek'])['id'];
        $ids = $this->fleetIdsByType($client, $profileId);
        for ($i = 1; $i <= 5; ++$i) {
            $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $this->preset("Zestaw $i", $ids));
            self::assertResponseStatusCodeSame(201);
        }

        $error = $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $this->preset('Zestaw 6', $ids));
        self::assertResponseStatusCodeSame(409);
        self::assertSame('FLEET_PRESET_LIMIT', $error['error']['code']);
    }

    public function testPlacementBreakingBoardRulesIsRejected(): void
    {
        $client = static::createClient();
        $profileId = $this->request($client, 'POST', '/api/profiles', ['name' => 'Jacek'])['id'];
        $ids = $this->fleetIdsByType($client, $profileId);

        $touching = ['name' => 'Styk', 'board' => ['w' => 7, 'h' => 7], 'ships' => [
            ['shipId' => $ids['kuter'][0], 'x' => 0, 'y' => 0, 'o' => 'H'],
            ['shipId' => $ids['tratwa'][0], 'x' => 2, 'y' => 1, 'o' => 'H'],
        ]];
        $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $touching);
        self::assertResponseStatusCodeSame(400);

        $foreign = ['name' => 'Obcy', 'board' => ['w' => 7, 'h' => 7], 'ships' => [
            ['shipId' => 'nie-moj', 'x' => 0, 'y' => 0, 'o' => 'H'],
        ]];
        $error = $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $foreign);
        self::assertResponseStatusCodeSame(404);
        self::assertSame('SHIP_NOT_FOUND', $error['error']['code']);

        $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", ['name' => 'Zły', 'board' => ['w' => 7], 'ships' => []]);
        self::assertResponseStatusCodeSame(400);
    }

    public function testRenameAndDelete(): void
    {
        $client = static::createClient();
        $profileId = $this->request($client, 'POST', '/api/profiles', ['name' => 'Jacek'])['id'];
        $ids = $this->fleetIdsByType($client, $profileId);
        $klin = $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $this->preset('Klin', $ids));
        $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $this->preset('Grot', $ids));

        $renamed = $this->request($client, 'PATCH', "/api/profiles/$profileId/fleet-presets/{$klin['id']}", ['name' => 'Łańcuch']);
        self::assertResponseStatusCodeSame(200);
        self::assertSame('Łańcuch', $renamed['name']);

        $error = $this->request($client, 'PATCH', "/api/profiles/$profileId/fleet-presets/{$klin['id']}", ['name' => 'grot']);
        self::assertResponseStatusCodeSame(409);
        self::assertSame('FLEET_PRESET_NAME_TAKEN', $error['error']['code']);

        $this->request($client, 'DELETE', "/api/profiles/$profileId/fleet-presets/{$klin['id']}");
        self::assertResponseStatusCodeSame(204);
        $this->request($client, 'DELETE', "/api/profiles/$profileId/fleet-presets/{$klin['id']}");
        self::assertResponseStatusCodeSame(404);

        $expedition = $this->request($client, 'GET', "/api/profiles/$profileId/expedition");
        self::assertSame(['Grot'], array_column($expedition['fleetPresets'], 'name'));
    }

    public function testScrappedShipStaysInPresetButLeavesFleet(): void
    {
        $client = static::createClient();
        $profileId = $this->request($client, 'POST', '/api/profiles', ['name' => 'Jacek'])['id'];
        $ids = $this->fleetIdsByType($client, $profileId);
        $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $this->preset('Klin', $ids));

        // złomowanie (#124) nie rusza zestawu — wczytanie (frontend) pominie statek spoza floty
        $this->request($client, 'POST', "/api/profiles/$profileId/ships/{$ids['tratwa'][0]}/scrap", ['islandId' => 'zatoka-rozbitka']);
        self::assertResponseIsSuccessful();

        $expedition = $this->request($client, 'GET', "/api/profiles/$profileId/expedition");
        self::assertSame([$ids['kuter'][0], $ids['tratwa'][0]], array_column($expedition['fleetPresets'][0]['ships'], 'shipId'));
        self::assertNotContains($ids['tratwa'][0], array_column($expedition['fleet'], 'id'));

        // ponowny zapis z tym statkiem jest już odrzucany
        $error = $this->request($client, 'POST', "/api/profiles/$profileId/fleet-presets", $this->preset('Klin', $ids));
        self::assertResponseStatusCodeSame(404);
        self::assertSame('SHIP_NOT_FOUND', $error['error']['code']);
    }

    public function testExpeditionExposesSquadOfUnsettledBattle(): void
    {
        $client = static::createClient();
        $profileId = $this->request($client, 'POST', '/api/profiles', ['name' => 'Jacek'])['id'];
        $ids = $this->fleetIdsByType($client, $profileId);
        self::assertEquals(new \stdClass(), json_decode($this->rawExpedition($client, $profileId))->battleSquads);

        $squad = [$ids['kuter'][0], $ids['tratwa'][1]];
        $battle = $this->request($client, 'POST', "/api/profiles/$profileId/islands/zatoka-rozbitka/battle", ['shipIds' => $squad]);
        self::assertResponseStatusCodeSame(201);

        $expedition = $this->request($client, 'GET', "/api/profiles/$profileId/expedition");
        self::assertSame([$battle['id'] => $squad], $expedition['battleSquads']);
    }

    /**
     * Kuter poziomo w rogu i tratwa w przeciwnym rogu planszy 7×7.
     *
     * @param array<string, list<string>> $ids
     *
     * @return array<string, mixed>
     */
    private function preset(string $name, array $ids): array
    {
        return ['name' => $name, 'board' => ['w' => 7, 'h' => 7], 'ships' => [
            ['shipId' => $ids['kuter'][0], 'x' => 0, 'y' => 0, 'o' => 'H'],
            ['shipId' => $ids['tratwa'][0], 'x' => 6, 'y' => 6, 'o' => 'V'],
        ]];
    }

    /** @return array<string, list<string>> typ → id statków floty */
    private function fleetIdsByType(KernelBrowser $client, string $profileId): array
    {
        $ids = [];
        foreach ($this->request($client, 'GET', "/api/profiles/$profileId/expedition")['fleet'] as $ship) {
            $ids[$ship['type']][] = $ship['id'];
        }

        return $ids;
    }

    private function rawExpedition(KernelBrowser $client, string $profileId): string
    {
        $client->request('GET', "/api/profiles/$profileId/expedition");

        return (string) $client->getResponse()->getContent();
    }

    /** @return array<string,mixed> */
    private function request(KernelBrowser $client, string $method, string $url, ?array $payload = null): array
    {
        $client->request($method, $url, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $payload ? json_encode($payload, JSON_THROW_ON_ERROR) : null);

        return json_decode($client->getResponse()->getContent() ?: '{}', true, 512, JSON_THROW_ON_ERROR) ?? [];
    }
}
