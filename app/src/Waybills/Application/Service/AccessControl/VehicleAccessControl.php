<?php

declare(strict_types=1);

namespace App\Waybills\Application\Service\AccessControl;

use App\Shared\Domain\Security\AuthUserFetcherInterface;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;

final readonly class VehicleAccessControl
{
    public function __construct(private AuthUserFetcherInterface $userFetcher)
    {
    }

    public function canAccess(): bool
    {
        return $this->userFetcher->isAuthenticated();
    }

    public function currentUserId(): string
    {
        return $this->userFetcher->getAuthUserId();
    }

    public function canLeave(Vehicle $vehicle): bool
    {
        return $this->userFetcher->isAuthenticated() && $vehicle->isDrivenBy($this->userFetcher->getAuthUserId());
    }
}
