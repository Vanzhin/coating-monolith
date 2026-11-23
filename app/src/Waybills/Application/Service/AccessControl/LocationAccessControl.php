<?php

declare(strict_types=1);

namespace App\Waybills\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Waybills\Domain\Aggregate\Location\Location;

final readonly class LocationAccessControl
{
    public function __construct(private AccessGuard $guard, private AuthUserFetcherInterface $userFetcher)
    {
    }

    public function currentUserId(): string
    {
        return $this->userFetcher->getAuthUserId();
    }

    public function canEdit(Location $location): bool
    {
        return $this->guard->isManager() || $location->isOwnedBy($this->currentUserId());
    }
}
