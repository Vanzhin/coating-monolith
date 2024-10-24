<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Security;

use App\Shared\Domain\Security\PublicKeyFetcherInterface;
use Lexik\Bundle\JWTAuthenticationBundle\Services\KeyLoader\KeyLoaderInterface;

final readonly class PublicKeyFetcher implements PublicKeyFetcherInterface
{
    public function __construct(private KeyLoaderInterface $keyLoader)
    {
    }

    public function getKey(): ?string
    {
        return $this->keyLoader->getPublicKey();
    }
}
