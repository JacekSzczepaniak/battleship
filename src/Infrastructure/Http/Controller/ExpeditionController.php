<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Expedition\BuildShip;
use App\Application\Expedition\CreateProfile;
use App\Application\Expedition\GetExpedition;
use App\Application\Expedition\RepairShip;
use App\Application\Expedition\Sail;
use App\Application\Expedition\SettleBattle;
use App\Application\Expedition\StartIslandBattle;
use App\Domain\Expedition\OwnedShip;
use App\Domain\Expedition\ShipType;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/profiles')]
final class ExpeditionController
{
    public function __construct(
        private CreateProfile $createProfile,
        private GetExpedition $getExpedition,
        private StartIslandBattle $startIslandBattle,
        private SettleBattle $settleBattle,
        private BuildShip $buildShip,
        private RepairShip $repairShip,
        private Sail $sail,
    ) {
    }

    #[Route('', name: 'api_profiles_create', methods: ['POST'])]
    public function create(Request $request): JsonResponse
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            throw new ApiException('Invalid JSON', 'VALIDATION_ERROR', 400);
        }

        $name = $payload['name'] ?? null;
        if (null !== $name && !is_string($name)) {
            throw new ApiException('Invalid field: name:string', 'VALIDATION_ERROR', 400);
        }

        $profile = $this->createProfile->handle($name);

        return new JsonResponse([
            'id' => (string) $profile->id(),
            'name' => $profile->name(),
            'xp' => $profile->xp(),
            'rank' => $profile->rank()->value,
        ], 201);
    }

    #[Route('/{id}/expedition', name: 'api_profiles_expedition', methods: ['GET'])]
    public function expedition(string $id): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        return new JsonResponse($this->getExpedition->handle($id));
    }

    #[Route('/{id}/islands/{islandId}/battle', name: 'api_profiles_island_battle', methods: ['POST'])]
    public function startBattle(string $id, string $islandId): JsonResponse
    {
        $this->assertUuid($id, 'profile');

        $game = $this->startIslandBattle->handle($id, $islandId);

        // Ten sam kształt co POST /api/games — frontend wchodzi w zwykły flow gry
        return new JsonResponse([
            'id' => (string) $game->id(),
            'status' => $game->status()->value,
            'ruleset' => $game->ruleset()->name(),
            'board' => [
                'w' => $game->ruleset()->boardSize()->width,
                'h' => $game->ruleset()->boardSize()->height,
            ],
        ], 201);
    }

    #[Route('/{id}/battles/{gameId}/settle', name: 'api_profiles_battle_settle', methods: ['POST'])]
    public function settle(string $id, string $gameId): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $this->assertUuid($gameId, 'game');

        return new JsonResponse($this->settleBattle->handle($id, $gameId));
    }

    #[Route('/{id}/sail', name: 'api_profiles_sail', methods: ['POST'])]
    public function sail(string $id, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $payload = $this->jsonPayload($request);

        $x = $payload['x'] ?? null;
        $y = $payload['y'] ?? null;
        if (!is_int($x) || !is_int($y)) {
            throw new ApiException('Missing or invalid fields: x:int, y:int', 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse($this->sail->handle($id, $x, $y));
    }

    #[Route('/{id}/ships', name: 'api_profiles_build_ship', methods: ['POST'])]
    public function buildShip(string $id, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $payload = $this->jsonPayload($request);

        $type = ShipType::tryFrom((string) ($payload['type'] ?? ''));
        $islandId = $payload['islandId'] ?? null;
        if (null === $type || !is_string($islandId)) {
            throw new ApiException('Missing or invalid fields: type:ship-type, islandId:string', 'VALIDATION_ERROR', 400);
        }

        $ship = $this->buildShip->handle($id, $islandId, $type);

        return new JsonResponse($this->shipView($ship), 201);
    }

    #[Route('/{id}/ships/{shipId}/repair', name: 'api_profiles_repair_ship', methods: ['POST'])]
    public function repairShip(string $id, string $shipId, Request $request): JsonResponse
    {
        $this->assertUuid($id, 'profile');
        $payload = $this->jsonPayload($request);

        $islandId = $payload['islandId'] ?? null;
        if (!is_string($islandId)) {
            throw new ApiException('Missing or invalid field: islandId:string', 'VALIDATION_ERROR', 400);
        }

        $ship = $this->repairShip->handle($id, $islandId, $shipId);

        return new JsonResponse($this->shipView($ship));
    }

    /** @return array<string,mixed> */
    private function shipView(OwnedShip $ship): array
    {
        return [
            'id' => $ship->id,
            'type' => $ship->type->value,
            'length' => $ship->type->length(),
            'damaged' => $ship->isDamaged(),
        ];
    }

    /** @return array<string,mixed> */
    private function jsonPayload(Request $request): array
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            throw new ApiException('Invalid JSON', 'VALIDATION_ERROR', 400);
        }

        return $payload;
    }

    private function assertUuid(string $id, string $what): void
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException(sprintf('Invalid %s id', $what), 'VALIDATION_ERROR', 400);
        }
    }
}
