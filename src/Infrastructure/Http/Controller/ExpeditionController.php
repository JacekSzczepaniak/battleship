<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Expedition\CreateProfile;
use App\Application\Expedition\GetExpedition;
use App\Application\Expedition\SettleBattle;
use App\Application\Expedition\StartIslandBattle;
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

    private function assertUuid(string $id, string $what): void
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException(sprintf('Invalid %s id', $what), 'VALIDATION_ERROR', 400);
        }
    }
}
