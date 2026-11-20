<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\DriverProfile;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use Symfony\Component\Uid\Uuid;

class DriverProfile extends Aggregate
{
    private RouteSettings $routeSettings;

    public function __construct(
        private Uuid $id,
        private readonly string $ownerId,
        private DriverDetails $driverDetails,
        Location $base,
        Destination ...$destinations,
    ) {
        if ('' === trim($ownerId)) {
            throw new AppException('Укажите владельца карточки водителя.');
        }
        $this->routeSettings = new RouteSettings($base, ...$destinations);
    }

    public function changeDriverDetails(DriverDetails $driverDetails): void
    {
        $this->driverDetails = $driverDetails;
    }

    public function changeBase(Location $base): void
    {
        $this->routeSettings = new RouteSettings($base, ...$this->routeSettings->destinations());
    }

    public function replaceDestinations(Destination ...$destinations): void
    {
        $this->routeSettings = new RouteSettings($this->routeSettings->base, ...$destinations);
    }

    public function isOwnedBy(string $ownerId): bool
    {
        return $this->ownerId === $ownerId;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function getOwnerId(): string
    {
        return $this->ownerId;
    }

    public function getDriverDetails(): DriverDetails
    {
        return $this->driverDetails;
    }

    public function getBase(): Location
    {
        return $this->routeSettings->base;
    }

    public function getRouteSettings(): RouteSettings
    {
        return $this->routeSettings;
    }

    /** @return list<Destination> */
    public function getDestinations(): array
    {
        return $this->routeSettings->destinations();
    }
}
