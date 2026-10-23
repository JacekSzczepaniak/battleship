<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\BoardShip;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class BoardShipController
{
    public function __construct(private BoardShip $boardShip)
    {
    }

    /** Abordaż ostatniego statku wroga (#118): rzut k6, ⚡3, kończy turę. */
    #[Route('/{id}/board', name: 'api_games_board', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException('Invalid game id', 'INVALID_GAME_ID', 400);
        }

        return new JsonResponse(($this->boardShip)($id));
    }
}
