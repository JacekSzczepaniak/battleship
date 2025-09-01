<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\CreateGame;
use App\Application\Game\PlaceFleet;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;

#[Route('/api/games')]
final class GameController
{
    public function __construct(
        private CreateGame $createGame,
        private PlaceFleet $placeFleet,
    ) {
    }

    #[Route('', name: 'api_games_create', methods: ['POST'])]
    public function create(Request $req): JsonResponse
    {
        $data = json_decode($req->getContent() ?: '{}', true) ?: [];
        $w = $data['width'] ?? null;
        $h = $data['height'] ?? null;

        $game = $this->createGame->handle($w, $h);

        return new JsonResponse([
            'id' => (string) $game->id(),
            'status' => $game->status()->value,
            'board' => [
                'w' => $game->ruleset()->boardSize()->width,
                'h' => $game->ruleset()->boardSize()->height,
            ],
        ], 201);
    }

    #[Route('/{id}/fleet', name: 'api_games_place_fleet', methods: ['POST'])]
    public function placeFleet(string $id, Request $req): JsonResponse
    {
        $payload = json_decode($req->getContent() ?: '{}', true) ?: [];
        $ships = $payload['ships'] ?? null;

        if (!is_array($ships) || [] === $ships) {
            return new JsonResponse(['error' => 'ships array required'], 400);
        }

        try {
            $this->placeFleet->handle($id, $ships);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        } catch (\Throwable $e) {
            return new JsonResponse(['error' => 'failed to place fleet'], 400);
        }

        return new JsonResponse(['ok' => true], 200);
    }

    #[Route('', name: 'api_games_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
