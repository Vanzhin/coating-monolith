<?php

declare(strict_types=1);

namespace App\Waybills\Application\Service\AccessControl;

use App\Shared\Application\Security\AccessGuard;
use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;

final readonly class WaybillAccessControl
{
    public function __construct(
        private AccessGuard $guard,
        private AuthUserFetcherInterface $userFetcher,
    ) {
    }

    public function currentUserId(): string
    {
        return $this->userFetcher->getAuthUserId();
    }

    public function isManager(): bool
    {
        return $this->guard->isManager();
    }

    public function canEdit(Vehicle $vehicle): bool
    {
        return $this->guard->isManager() || ($this->userFetcher->isAuthenticated() && $vehicle->isDrivenBy($this->userFetcher->getAuthUserId()));
    }

    public function canCreate(): bool
    {
        return $this->userFetcher->isAuthenticated();
    }

    public function canCreateFor(Vehicle $vehicle): bool
    {
        return $this->userFetcher->isAuthenticated() && $vehicle->isDrivenBy($this->userFetcher->getAuthUserId());
    }
}
