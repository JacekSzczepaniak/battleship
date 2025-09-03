<?php

declare(strict_types=1);

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\PlaceFleet;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;

final class PlaceFleetController
{
    public function __construct(private readonly PlaceFleet $handler)
    {
    }

    #[Route('/api/games/{id}/fleet', name: 'api_games_place_fleet', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        $data = json_decode($request->getContent() ?: '[]', true);

        if (!is_array($data) || !isset($data['ships']) || !is_array($data['ships'])) {
            return new JsonResponse(['error' => 'Invalid payload: expected {"ships":[...]}'], 400);
        }

        // oczekujemy elementów: {x:int,y:int,o:"H"|"V",l:int}
        $ships = [];
        foreach ($data['ships'] as $i => $s) {
            if (!isset($s['x'], $s['y'], $s['o'], $s['l'])) {
                return new JsonResponse(['error' => "Invalid ship at index $i"], 400);
            }
            $ships[] = [
                'x' => (int) $s['x'],
                'y' => (int) $s['y'],
                'o' => (string) $s['o'],
                'l' => (int) $s['l'],
            ];
        }

        try {
            $this->handler->handle($id, $ships);
        } catch (\DomainException $e) {
            return new JsonResponse(['error' => $e->getMessage()], 422);
        }

        return new JsonResponse(['ok' => true], 200);
    }
}
