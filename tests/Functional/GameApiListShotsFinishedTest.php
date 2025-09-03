<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Kernel;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

final class GameApiListShotsFinishedTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testFinishedAfterSinkingEntireFleet(): void
    {
        $client = static::createClient();

        // 1) create
        $client->request(
            'POST',
            '/api/games',
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['width' => 12, 'height' => 10], JSON_THROW_ON_ERROR)
        );
        self::assertResponseStatusCodeSame(201);
        $id = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR)['id'];

        // 2) place fleet (pełna, zgodna z ClassicRuleset)
        $fleet = FleetFactory::classic10x10Array();
        $client->request(
            'POST',
            "/api/games/$id/fleet",
            server: ['CONTENT_TYPE' => 'application/json'],
            content: json_encode(['ships' => $fleet], JSON_THROW_ON_ERROR)
        );
        self::assertResponseIsSuccessful();

        // 3) sink everything — oddaj strzały we wszystkie pola wszystkich statków
        foreach ($fleet as $s) {
            $x = $s['x'];
            $y = $s['y'];
            $len = $s['l'];
            $horiz = ('h' === strtolower((string) $s['o']));

            for ($i = 0; $i < $len; ++$i) {
                $sx = $x + ($horiz ? $i : 0);
                $sy = $y + ($horiz ? 0 : $i);

                $client->request(
                    'POST',
                    "/api/games/$id/shots",
                    server: ['CONTENT_TYPE' => 'application/json'],
                    content: json_encode(['x' => $sx, 'y' => $sy], JSON_THROW_ON_ERROR)
                );
                self::assertResponseIsSuccessful();
            }
        }

        // 4) GET list of shots — finished powinno być true
        $client->request('GET', "/api/games/$id/shots");
        self::assertResponseIsSuccessful();
        $payload = json_decode($client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);

        // DEBUG: wylicz, które komórki floty nie są oznaczone jako trafione (hit/sunk)
        $hitSet = [];
        foreach ($payload['shots'] as $s) {
            if (in_array($s['result'], ['hit', 'sunk'], true)) {
                $hitSet[$s['x'].':'.$s['y']] = true;
            }
        }
        $missing = [];
        foreach ($fleet as $ship) {
            $x = $ship['x'];
            $y = $ship['y'];
            $len = $ship['l'];
            $horiz = ('h' === strtolower((string) $ship['o']));
            for ($i = 0; $i < $len; ++$i) {
                $sx = $x + ($horiz ? $i : 0);
                $sy = $y + ($horiz ? 0 : $i);
                $key = $sx.':'.$sy;
                if (!isset($hitSet[$key])) {
                    $missing[] = $key;
                }
            }
        }

        // Opcjonalny zrzut do STDERR (nie jest flagowany jako "zapomniany debug")
        if (!empty($missing)) {
            fwrite(STDERR, 'DEBUG payload: '.json_encode($payload, JSON_PRETTY_PRINT).PHP_EOL);
            fwrite(STDERR, 'DEBUG missing-hit-cells: '.json_encode($missing).PHP_EOL);
        }

        self::assertArrayHasKey('finished', $payload);
        self::assertTrue(
            $payload['finished'],
            'Gra powinna być zakończona po zatopieniu całej floty. '
            .'Missing-hit-cells='.json_encode($missing).'; '
            .'payload='.json_encode($payload)
        );
    }
}
