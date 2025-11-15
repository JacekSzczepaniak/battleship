<?php


namespace App\Infrastructure\Http\Controller;

use App\Application\Game\SonarPing;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class SonarController
{
    public function __construct(private SonarPing $sonarPing)
    {
    }

    #[Route('/{id}/sonar', name: 'api_games_sonar', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException('Invalid game id', 'INVALID_GAME_ID', 400);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            throw new ApiException('Invalid JSON', 'VALIDATION_ERROR', 400);
        }

        $x = $payload['x'] ?? null;
        $y = $payload['y'] ?? null;
        $radius = $payload['radius'] ?? 3;

        if (!is_int($x) || !is_int($y) || !is_int($radius) || $radius < 0) {
            throw new ApiException('Missing or invalid fields: x:int, y:int, radius:int>=0', 'VALIDATION_ERROR', 400);
        }

        // DomainException zostanie obsłużony przez ExceptionSubscriber
        $list = ($this->sonarPing)($id, $x, $y, $radius);

        return new JsonResponse([
            'results' => $list, // list of {x,y,occupied}
            'shape' => 'cross',
            'radius' => $radius,
        ]);
    }
}
