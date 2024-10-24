<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

#[Route('/health-check', name: 'health_check', methods: ['GET'])]
class HealthCheckAction
{
    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['status' => 'ok']);
    }
}
