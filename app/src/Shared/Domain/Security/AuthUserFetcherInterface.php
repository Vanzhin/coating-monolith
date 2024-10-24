<?php

declare(strict_types=1);

namespace App\Shared\Domain\Security;

interface AuthUserFetcherInterface
{
    public function getAuthUser(): AuthUserInterface;

    public function getAuthUserId(): string;
}
