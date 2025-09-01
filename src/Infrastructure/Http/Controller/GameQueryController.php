<?php

namespace App\Infrastructure\Http\Controller;

use App\Domain\Game\GameRepository;
use App\Domain\Shared\GameId;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class GameQueryController
{
    public function __construct(private GameRepository $repo)
    {
    }

    #[Route('/{id}', name: 'api_games_get', methods: ['GET'])]
    public function get(string $id): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid game id'], 400);
        }

        $game = $this->repo->get(new GameId($id));
        if (!$game) {
            return new JsonResponse(['error' => 'Game not found'], 404);
        }

        $size = $game->ruleset()->boardSize();

        return new JsonResponse([
            'id' => (string) $game->id(),
            'status' => $game->status()->value, // enum -> string
            'board' => ['w' => $size->width, 'h' => $size->height],
        ]);
    }
}
