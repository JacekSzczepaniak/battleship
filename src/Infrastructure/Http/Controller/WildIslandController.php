<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Expedition\ExploreWildIsland;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Ekspedycje w głąb dzikich wysp (#144). Ryzyko i nagrody etapów, stan
 * ekspedycji i odpoczynek wyspy daje GET /expedition (world.wildIsland).
 */
#[Route('/api/profiles/{id}/wild-islands/{islandId}')]
final class WildIslandController
{
    public function __construct(private ExploreWildIsland $explore)
    {
    }

    /** {action: start|deeper|return}: etap 1, następny etap albo powrót z zebranymi nagrodami. */
    #[Route('/expedition', name: 'api_profiles_wild_island_expedition', methods: ['POST'])]
    public function expedition(string $id, string $islandId, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException('Invalid profile id', 'VALIDATION_ERROR', 400);
        }
        $payload = json_decode($request->getContent() ?: '{}', true);
        $action = is_array($payload) ? ($payload['action'] ?? null) : null;
        if (!in_array($action, ExploreWildIsland::ACTIONS, true)) {
            throw new ApiException(sprintf('Missing or invalid field: action:%s', implode('|', ExploreWildIsland::ACTIONS)), 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse($this->explore->handle($id, $islandId, $action));
    }
}
