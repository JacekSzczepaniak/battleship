<?php

namespace App\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Kernel;
use Symfony\Component\Routing\Annotation\Route;

final class HealthCheckController
{
    #[Route('/healthz', name: 'healthz', methods: ['GET'])]
    public function index(): JsonResponse
    {
        return new JsonResponse([
            'status' => 'ok',
            'php' => PHP_VERSION,
            'symfony' => Kernel::VERSION,
        ]);
    }
}
