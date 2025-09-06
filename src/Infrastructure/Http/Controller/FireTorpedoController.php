<?php


namespace App\Infrastructure\Http\Controller;

use App\Application\Game\FireTorpedo;
use App\Domain\Game\Direction;
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
            return new JsonResponse(['error' => 'Invalid game id'], 400);
        }

        $payload = json_decode($request->getContent() ?: '{}', true);
        if (!is_array($payload)) {
            return new JsonResponse(['error' => 'Invalid JSON'], 400);
        }

        $x = $payload['x'] ?? null;
        $y = $payload['y'] ?? null;
        $dir = $payload['direction'] ?? null;

        if (!is_int($x) || !is_int($y) || !is_string($dir)) {
            return new JsonResponse(['error' => 'Missing or invalid fields: x:int, y:int, direction:string(N|E|S|W)'], 400);
        }

        $direction = match (strtoupper($dir)) {
            'N' => Direction::N,
            'E' => Direction::E,
            'S' => Direction::S,
            'W' => Direction::W,
            default => null,
        };

        if (null === $direction) {
            return new JsonResponse(['error' => 'Invalid direction'], 400);
        }

        try {
            $list = ($this->fireTorpedo)($id, $x, $y, $direction);
        } catch (\DomainException $ex) {
            // Align with FireShot: when fleet is not placed -> 422; otherwise -> 400
            $code = ('Fleet not placed' === $ex->getMessage()) ? 422 : 400;
            return new JsonResponse(['error' => $ex->getMessage()], $code);
        }

        return new JsonResponse([
            'results' => $list, // list of {x,y,result}
        ]);
    }
}
