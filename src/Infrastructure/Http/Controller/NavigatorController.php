<?php

namespace App\Infrastructure\Http\Controller;

use App\Application\Expedition\PlanVoyage;
use App\Infrastructure\Http\Error\ApiException;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Uid\Uuid;

/**
 * Nawigator (#153): plan trasy po odkrytych sektorach i przez otwarte bramy.
 * Trasę płynie się zwykłymi rejsami i przejściami przez bramy; cele trasy
 * daje GET /expedition (world.navigator).
 */
#[Route('/api/profiles/{id}/navigator')]
final class NavigatorController
{
    public function __construct(private PlanVoyage $planVoyage)
    {
    }

    /** ?sea=&x=&y=&avoidUnvisited=0|1: kroki, koszt w tikach, sektory ze sztormem i zdążenie przed kontratakiem. */
    #[Route('/route', name: 'api_profiles_navigator_route', methods: ['GET'])]
    public function route(string $id, Request $request): JsonResponse
    {
        if (!Uuid::isValid($id)) {
            throw new ApiException('Invalid profile id', 'VALIDATION_ERROR', 400);
        }
        $sea = $request->query->get('sea');
        $x = filter_var($request->query->get('x'), FILTER_VALIDATE_INT);
        $y = filter_var($request->query->get('y'), FILTER_VALIDATE_INT);
        if (!is_string($sea) || '' === $sea || false === $x || false === $y) {
            throw new ApiException('Missing or invalid query: sea:string, x:int, y:int', 'VALIDATION_ERROR', 400);
        }

        return new JsonResponse($this->planVoyage->handle($id, $sea, $x, $y, $request->query->getBoolean('avoidUnvisited')));
    }
}
