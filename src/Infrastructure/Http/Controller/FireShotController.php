<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\FireShot;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class FireShotController
{
    public function __construct(private readonly FireShot $fire)
    {
    }

    #[Route('/api/games/{id}/shots', name: 'api_games_fire', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent() ?: '[]', true);
        if (!is_array($data) || !isset($data['x'], $data['y'])) {
            return new JsonResponse(['error' => 'Invalid payload: expected {"x":int,"y":int}'], 400);
        }
        try {
            $result = $this->fire->handle($id, (int) $data['x'], (int) $data['y']);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 404);
        }

        return new JsonResponse($result, 200);
    }
}
