<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\GetShots;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;

final readonly class ListShotsController
{
    public function __construct(private GetShots $query)
    {
    }

    #[Route('/api/games/{id}/shots', name: 'api_games_list_shots', methods: ['GET'])]
    public function __invoke(string $id): JsonResponse
    {
        // Wyjątki typu InvalidArgumentException (np. "Game not found") przejmie ExceptionSubscriber
        return new JsonResponse($this->query->handle($id));
    }
}
