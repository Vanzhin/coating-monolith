<?php

declare(strict_types=1);

namespace App\Waybills\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;

final readonly class DriverProfileAccessControl
{
    public function __construct(private AccessGuard $guard, private AuthUserFetcherInterface $userFetcher)
    {
    }

    public function currentUserId(): string
    {
        return $this->userFetcher->getAuthUserId();
    }

    public function canEdit(DriverProfile $profile): bool
    {
        return $this->guard->isManager() || $profile->isOwnedBy($this->currentUserId());
    }
}
