<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Game\SendAirRaid;
use http\Env\Request;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Uid\Uuid;

#[Route('/api/games')]
final class SendAirRaidController
{
    public function __construct(private SendAirRaid $sendAirRaid)
    {
    }

    #[Route('{id}/air-raid', name: 'api_games_air_raid', methods: ['POST'])]
    public function __invoke(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            return new JsonResponse(['error' => 'Invalid game id'], 400);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $x = $payload['x'] ?? null;
        $y = $payload['y'] ?? null;
        $width = $payload['width'] ?? null;
        $height = $payload['height'] ?? null;

        if (!is_int($x) || !is_int($y) || !is_int($width) || !is_int($height)) {
            return new JsonResponse(['error' => 'Missing or invalid fields: x:int, y:int, width:int, height:int'], 400);
        }

        try {
            $list = ($this->sendAirRaid)($id, $x, $y, $width, $height);
        } catch (\DomainException $ex) {

            $code = ('Fleet not placed' === $ex->getMessage()) ? 422 : 400;
            return new JsonResponse(['error' => $ex->getMessage()], $code);
        }

        return new JsonResponse([
            'result' => $list, //list of {x,y,result?}
        ]);

    }
}
