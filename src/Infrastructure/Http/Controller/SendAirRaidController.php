<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\SendAirRaid;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class SendAirRaidController
{
    public function __construct(private SendAirRaid $sendAirRaid)
    {
    }

    #[Route('/{id}/air-raid', name: 'api_games_air_raid', methods: ['POST'])]
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
        $width = $payload['width'] ?? null;
        $height = $payload['height'] ?? null;

        if (!is_int($x) || !is_int($y) || !is_int($width) || !is_int($height)) {
            throw new ApiException('Missing or invalid fields: x:int, y:int, width:int, height:int', 'VALIDATION_ERROR', 400);
        }

        // DomainException zostanie obsłużony przez ExceptionSubscriber (422/400)
        // results: list of {x,y,result} + win/loss/finished/turn/opponentMoves
        return new JsonResponse(($this->sendAirRaid)($id, $x, $y, $width, $height));
    }
}
