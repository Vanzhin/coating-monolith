<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Controller;

use App\Shared\Infrastructure\Security\PublicKeyFetcher;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpKernel\Attribute\AsController;
use Symfony\Component\Routing\Attribute\Route;

#[AsController]
#[Route('/api/auth/public-key', name: 'public_key', methods: ['GET'])]
final readonly class GetPublicKeyAction
{
    public function __construct(private PublicKeyFetcher $fetcher)
    {
    }

    public function __invoke(): JsonResponse
    {
        return new JsonResponse(['public_key' => $this->fetcher->getKey()]);
    }
}
