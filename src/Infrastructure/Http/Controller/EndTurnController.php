<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\EndTurn;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class EndTurnController
{
    public function __construct(private EndTurn $endTurn)
    {
    }

    /** „Zakończ turę” (#59): tura przechodzi do AI bez ataku gracza. */
    #[Route('/{id}/end-turn', name: 'api_games_end_turn', methods: ['POST'])]
    public function __invoke(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException('Invalid game id', 'INVALID_GAME_ID', 400);
        }

        return new JsonResponse(($this->endTurn)($id));
    }
}
