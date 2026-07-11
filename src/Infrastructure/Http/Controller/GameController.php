<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\CreateGame;
use App\Application\Game\PlaceFleet;
use App\Infrastructure\Http\Error\ApiException;
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
        $mode = $data['mode'] ?? 'classic';

        if (!in_array($mode, ['classic', 'fun'], true)) {
            throw new ApiException('Invalid mode: expected classic|fun', 'VALIDATION_ERROR', 400);
        }

        // Opcjonalny skład floty: mapa długość => liczba sztuk (brak = flota klasyczna).
        // Klucze JSON to stringi; walidację wartości robi domena (FleetComposition).
        $ships = $data['ships'] ?? null;
        if (null !== $ships) {
            if (!is_array($ships) || [] === $ships) {
                throw new ApiException('Invalid ships: expected map {length: count}', 'VALIDATION_ERROR', 400);
            }
            $parsed = [];
            foreach ($ships as $length => $count) {
                if (!is_int($count)) {
                    throw new ApiException('Invalid ships: expected map {length: count}', 'VALIDATION_ERROR', 400);
                }
                $parsed[(int) $length] = $count;
            }
            $ships = $parsed;
        }

        $game = $this->createGame->handle($w, $h, $mode, $ships);

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

    #[Route('/{id}/fleet', name: 'api_games_place_fleet', methods: ['POST'])]
    public function placeFleet(string $id, Request $req): JsonResponse
    {
        $payload = json_decode($req->getContent() ?: '{}', true) ?: [];
        $ships = $payload['ships'] ?? null;

        if (!is_array($ships) || [] === $ships) {
            throw new ApiException('ships array required', 'VALIDATION_ERROR', 400);
        }

        // Pozwól wyjątków domenowych/argumentów zostać przechwyconymi przez ExceptionSubscriber
        $this->placeFleet->handle($id, $ships);

        return new JsonResponse(['ok' => true], 200);
    }

    #[Route('', name: 'api_games_ping', methods: ['GET'])]
    public function ping(): JsonResponse
    {
        return new JsonResponse(['ok' => true]);
    }
}
