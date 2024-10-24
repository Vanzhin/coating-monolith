<?php

declare(strict_types=1);

namespace App\Shared\Domain\Security;

interface PublicKeyFetcherInterface
{
    public function getKey(): ?string;
}
