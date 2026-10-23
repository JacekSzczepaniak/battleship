<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\MoveShip;
use App\Domain\Game\Direction;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class MoveShipController
{
    private const DIRECTIONS = ['N', 'E', 'S', 'W'];

    public function __construct(private MoveShip $moveShip)
    {
    }

    /** Ruch statku o 1 pole (#60): {x, y} = dowolne pole statku, direction N|E|S|W. */
    #[Route('/{id}/move', name: 'api_games_move', methods: ['POST'])]
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
        $direction = $payload['direction'] ?? null;

        if (!is_int($x) || !is_int($y) || $x < 0 || $y < 0 || !in_array($direction, self::DIRECTIONS, true)) {
            throw new ApiException('Missing or invalid fields: x:int>=0, y:int>=0, direction:N|E|S|W', 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse(($this->moveShip)($id, $x, $y, Direction::from($direction)));
    }
}
