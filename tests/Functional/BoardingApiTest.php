<?php

declare(strict_types=1);

namespace Tests\Functional;

use App\Application\Expedition\WorldFactory;
use App\Application\Game\CreateGame;
use App\Domain\Expedition\CaptainProfile;
use App\Domain\Expedition\Prize;
use App\Domain\Expedition\ProfileRepository;
use App\Domain\Expedition\Resources;
use App\Domain\Expedition\SeaGraph;
use App\Domain\Expedition\ShipType;
use App\Domain\Expedition\StaticSeaGraph;
use App\Domain\Game\Boarding\BoardingRoll;
use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;
use App\Domain\Shared\ProfileId;
use App\Kernel;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Tests\Support\FleetFactory;

/**
 * Abordaż (#118) przez API. Wrogie statki zatapiamy przez repozytorium, a numer
 * tury ustawiamy tak, żeby rzut k6 wypadł znanym wynikiem (rzut zależy od id gry i tury).
 */
final class BoardingApiTest extends WebTestCase
{
    protected static function getKernelClass(): string
    {
        return Kernel::class;
    }

    public function testTrainingBattleHasNoBoarding(): void
    {
        $client = static::createClient();
        foreach (['fun', 'classic'] as $mode) {
            $id = $this->json($client, 'POST', '/api/games', ['mode' => $mode], 201)['id'];
            $this->json($client, 'POST', "/api/games/$id/fleet", ['ships' => FleetFactory::classic10x10Array()]);

            self::assertNull($this->json($client, 'GET', "/api/games/$id")['boarding'], $mode);
            $err = $this->json($client, 'POST', "/api/games/$id/board", status: 422);
            self::assertSame('BOARDING_NOT_AVAILABLE', $err['error']['code'], $mode);
        }
    }

    public function testFailedBoardingCostsTheTurnAndCannotBeRepeated(): void
    {
        $client = static::createClient();
        $id = (string) static::getContainer()->get(CreateGame::class)->handle(10, 10, 'fun', boarding: true)->id();
        $this->json($client, 'POST', "/api/games/$id/fleet", ['ships' => FleetFactory::classic10x10Array()]);

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame(['available' => false, 'blocker' => 'not-last-ship', 'threshold' => 5, 'modifiers' => [], 'cost' => 3, 'attempt' => null], $view['boarding']);
        $err = $this->json($client, 'POST', "/api/games/$id/board", status: 422);
        self::assertSame('BOARDING_NOT_LAST_SHIP', $err['error']['code']);

        $this->leaveLastEnemyShip($id, length: 2, roll: static fn (int $roll) => $roll < 5);
        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertTrue($view['boarding']['available']);

        $out = $this->json($client, 'POST', "/api/games/$id/board");
        self::assertFalse($out['boarding']['success']);
        self::assertSame(5, $out['boarding']['threshold']);
        self::assertSame('player', $out['turn']);
        self::assertSame(3, $out['energy']['left']);

        $view = $this->json($client, 'GET', "/api/games/$id");
        self::assertSame('attempted', $view['boarding']['blocker']);
        self::assertSame($out['boarding'], $view['boarding']['attempt']);
        $err = $this->json($client, 'POST', "/api/games/$id/board", status: 409);
        self::assertSame('BOARDING_ALREADY_ATTEMPTED', $err['error']['code']);
    }

    public function testBoardedShipWaitsForTheCaptainsDecision(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtStrait($client);

        $game = $this->json($client, 'POST', "/api/profiles/$profileId/gates/".StaticSeaGraph::GUARDED_STRAIT.'/battle', status: 201);
        $id = $game['id'];
        // kuter + 3 tratwy na planszy 12×12
        $this->json($client, 'POST', "/api/games/$id/fleet", ['ships' => [
            ['x' => 0, 'y' => 0, 'o' => 'H', 'l' => 2],
            ['x' => 4, 'y' => 4, 'o' => 'H', 'l' => 1],
            ['x' => 8, 'y' => 8, 'o' => 'H', 'l' => 1],
            ['x' => 11, 'y' => 0, 'o' => 'H', 'l' => 1],
        ]]);

        // ostatni okręt strażnika: kuter (sonar nie zatopi floty gracza w jednej turze)
        $this->leaveLastEnemyShip($id, length: 2, roll: static fn (int $roll) => $roll >= 5);
        $out = $this->json($client, 'POST', "/api/games/$id/board");
        self::assertTrue($out['boarding']['success']);
        self::assertTrue($out['win']);
        self::assertSame('none', $out['turn']);

        $settled = $this->json($client, 'POST', "/api/profiles/$profileId/battles/$id/settle");
        self::assertSame('won', $settled['result']);
        self::assertTrue($settled['gateOpened']);
        self::assertSame('kuter', $settled['prize']['type']);

        $expedition = $this->json($client, 'GET', "/api/profiles/$profileId/expedition");
        self::assertCount(1, $expedition['prizes']);
        self::assertSame(['wood' => 10, 'steel' => 0, 'gold' => 0], $expedition['prizes'][0]['scrapValue']);
        self::assertSame('ship-boarded', $expedition['log'][0]['event']);
        self::assertSame('Strzeżona cieśnina', $expedition['log'][0]['gateName']);
        $fleetBefore = count($expedition['fleet']);

        $prizeId = $settled['prize']['id'];
        $err = $this->json($client, 'POST', "/api/profiles/$profileId/prizes/$prizeId", ['decision' => 'sell'], 400);
        self::assertSame('VALIDATION_ERROR', $err['error']['code']);

        $joined = $this->json($client, 'POST', "/api/profiles/$profileId/prizes/$prizeId", ['decision' => 'join']);
        self::assertSame('join', $joined['decision']);
        self::assertSame(['type' => 'kuter', 'damaged' => true], array_intersect_key($joined['ship'], ['type' => 1, 'damaged' => 1]));

        $expedition = $this->json($client, 'GET', "/api/profiles/$profileId/expedition");
        self::assertSame([], $expedition['prizes']);
        self::assertCount($fleetBefore + 1, $expedition['fleet']);
        self::assertSame('prize-decided', $expedition['log'][0]['event']);

        $err = $this->json($client, 'POST', "/api/profiles/$profileId/prizes/$prizeId", ['decision' => 'scrap'], 404);
        self::assertSame('PRIZE_NOT_FOUND', $err['error']['code']);
    }

    public function testScrapLoadsHalfTheBuildCostIntoTheHold(): void
    {
        $client = static::createClient();
        $profileId = $this->captainAtStrait($client, prize: new Prize('5c4a0000-0000-4000-8000-000000000118', ShipType::Niszczyciel, null, 0));

        $scrapped = $this->json($client, 'POST', "/api/profiles/$profileId/prizes/5c4a0000-0000-4000-8000-000000000118", ['decision' => 'scrap']);

        self::assertSame('scrap', $scrapped['decision']);
        self::assertNull($scrapped['ship']);
        // niszczyciel kosztuje 30 drewna i 20 stali; ładownia startowej floty mieści 15, reszta przepada
        $total = static fn (array $r) => $r['wood'] + $r['steel'] + $r['gold'];
        self::assertSame(15, $total($scrapped['refund']));
        self::assertSame(25, $total($scrapped['refund']) + $total($scrapped['cargoLost']));
        self::assertSame(['wood' => 15, 'steel' => 10, 'gold' => 0], [
            'wood' => $scrapped['refund']['wood'] + $scrapped['cargoLost']['wood'],
            'steel' => $scrapped['refund']['steel'] + $scrapped['cargoLost']['steel'],
            'gold' => 0,
        ]);
        self::assertSame($scrapped['refund'], $scrapped['cargo']);
    }

    /**
     * Zatapia wszystkie statki wroga poza jednym o danej długości i ustawia turę,
     * w której rzut k6 tej gry spełnia warunek.
     */
    private function leaveLastEnemyShip(string $id, int $length, callable $roll): void
    {
        $repo = static::getContainer()->get(GameRepository::class);
        $game = $repo->get(new GameId($id));
        $spared = false;
        foreach ($game->opponentFleet() as $ship) {
            if (!$spared && $ship->length === $length) {
                $spared = true;
                continue;
            }
            foreach ($ship->cells() as $cell) {
                $game->fireShot($cell);
            }
        }
        self::assertTrue($spared);

        $round = 1;
        while (!$roll(BoardingRoll::d6($id, $round))) {
            ++$round;
        }
        $game->setRoundFromSnapshot($round);
        $repo->save($game);
    }

    /** Porucznik (strażnik wymaga rangi) przy strzeżonej cieśninie, z pustą ładownią floty startowej. */
    private function captainAtStrait(KernelBrowser $client, ?Prize $prize = null): string
    {
        $profileId = $this->json($client, 'POST', '/api/profiles', ['name' => 'Jacek'], 201)['id'];
        $repository = static::getContainer()->get(ProfileRepository::class);
        $profile = $repository->get(new ProfileId($profileId));
        $world = static::getContainer()->get(WorldFactory::class)->mapOf(static::getContainer()->get(SeaGraph::class)->sea($profile->currentSea()), $profile);
        $position = $world->gatePosition(StaticSeaGraph::GUARDED_STRAIT);

        $repository->save(CaptainProfile::fromSnapshot(
            $profile->id(), $profile->name(), 400, [], $profile->fleet(), Resources::none(),
            $profile->worldSeed(), $position, ["{$position['x']}:{$position['y']}"],
            prizes: null !== $prize ? [$prize] : [],
        ));

        return $profileId;
    }

    /** @return array<string,mixed> */
    private function json(KernelBrowser $client, string $method, string $uri, ?array $body = null, int $status = 200): array
    {
        $client->request($method, $uri, server: ['CONTENT_TYPE' => 'application/json'], content: null !== $body ? json_encode($body, JSON_THROW_ON_ERROR) : null);
        self::assertResponseStatusCodeSame($status, (string) $client->getResponse()->getContent());

        return json_decode((string) $client->getResponse()->getContent(), true, 512, JSON_THROW_ON_ERROR);
    }
}
