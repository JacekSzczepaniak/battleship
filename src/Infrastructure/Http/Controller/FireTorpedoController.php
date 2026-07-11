<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\FireTorpedo;
use App\Domain\Game\Direction;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class FireTorpedoController
{
    public function __construct(private FireTorpedo $fireTorpedo)
    {
    }

    #[Route('/{id}/torpedo', name: 'api_games_torpedo', methods: ['POST'])]
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
        $dir = $payload['direction'] ?? null;

        if (!is_int($x) || !is_int($y) || !is_string($dir)) {
            throw new ApiException('Missing or invalid fields: x:int, y:int, direction:string(N|NE|E|SE|S|SW|W|NW)', 'VALIDATION_ERROR', 400);
        }

        $direction = Direction::tryFrom(strtoupper($dir));

        if (null === $direction) {
            throw new ApiException('Invalid direction', 'VALIDATION_ERROR', 400);
        }

        // DomainException zostanie zamienione na spójny JSON przez ExceptionSubscriber
        // results: list of {x,y,result} + win/loss/finished/turn/opponentMoves
        return new JsonResponse(($this->fireTorpedo)($id, $x, $y, $direction));
    }
}
