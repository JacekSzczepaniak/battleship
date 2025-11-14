<?php


namespace App\Infrastructure\Http\Controller;

use App\Application\Game\SonarPing;
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
            return new JsonResponse(['error' => 'Invalid game id'], 400);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $x = $payload['x'] ?? null;
        $y = $payload['y'] ?? null;
        $radius = $payload['radius'] ?? 3;

        if (!is_int($x) || !is_int($y) || !is_int($radius) || $radius < 0) {
            return new JsonResponse(['error' => 'Missing or invalid fields: x:int, y:int, radius:int>=0'], 400);
        }

        try {
            $list = ($this->sonarPing)($id, $x, $y, $radius);
        } catch (\DomainException $ex) {
            // Align with other endpoints: 'Fleet not placed' => 422; unknown game or others => 400
            $code = ('Fleet not placed' === $ex->getMessage()) ? 422 : 400;
            return new JsonResponse(['error' => $ex->getMessage()], $code);
        }

        return new JsonResponse([
            'results' => $list, // list of {x,y,occupied}
            'shape' => 'cross',
            'radius' => $radius,
        ]);
    }
}
