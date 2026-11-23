<?php

declare(strict_types=1);

namespace App\Waybills\Application\Service;

use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\DTO\TripInput;
use App\Waybills\Application\Service\AccessControl\LocationAccessControl;
use App\Waybills\Domain\Aggregate\Location\Location as CatalogLocation;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Domain\Repository\LocationFilter;
use App\Waybills\Domain\Repository\LocationRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class LocationCatalog
{
    public function __construct(private LocationRepositoryInterface $locations, private LocationAccessControl $access)
    {
    }

    public function resolve(Location $address): Location
    {
        if (null !== $address->id) {
            $location = $this->locations->findOneById((string) $address->id);
            if (null === $location) {
                throw new AppException('Точка маршрута не найдена.');
            }
            if (!$this->access->canEdit($location)) {
                throw new ForbiddenException();
            }
            $current = $location->snapshot();
            if ($this->sameAddress($current, $address)) {
                return $current;
            }

            return $this->resolve($address->withId(null));
        }

        $ownerId = $this->access->currentUserId();
        $existing = $this->locations->findByFilter(new LocationFilter(ownerId: $ownerId));
        foreach ($existing->items as $location) {
            if ($this->sameAddress($location->snapshot(), $address)) {
                return $location->snapshot();
            }
        }

        $location = new CatalogLocation(Uuid::v7(), $ownerId, $address, new \DateTimeImmutable());
        $this->locations->add($location);

        return $location->snapshot();
    }

    public function synchronizeSettings(RouteSettings $settings): RouteSettings
    {
        $destinations = [];
        foreach ($settings->destinations() as $destination) {
            $destinations[] = new Destination($this->synchronize($destination->location), $destination->distance);
        }

        return new RouteSettings($this->synchronize($settings->base), ...$destinations);
    }

    /** @return list<TripInput> */
    public function resolveTrips(TripInput ...$trips): array
    {
        return array_map(function (TripInput $trip): TripInput {
            $location = $this->resolve($trip->destination->location);
            $original = $trip->originalDestination;
            if (null !== $original && $this->sameAddress($original->location, $trip->destination->location) && $original->location->samePlace($trip->destination->location)) {
                $original = new Destination($location, $original->distance);
            }

            return new TripInput(
                $trip->id,
                $trip->date,
                new Destination($location, $trip->destination->distance),
                $trip->origin,
                $trip->pairId,
                $trip->originalDate,
                $original,
            );
        }, $trips);
    }

    private function synchronize(Location $address): Location
    {
        if (null === $address->id) {
            return $this->resolve($address);
        }
        $location = $this->locations->findOneById((string) $address->id);
        if (null === $location) {
            throw new AppException('Точка маршрута не найдена.');
        }
        if (!$this->access->canEdit($location)) {
            throw new ForbiddenException();
        }
        $location->change($address, new \DateTimeImmutable());

        return $location->snapshot();
    }

    private function sameAddress(Location $left, Location $right): bool
    {
        return $left->equals($right)
            && $left->enterprise === $right->enterprise
            && $left->latitude === $right->latitude
            && $left->longitude === $right->longitude;
    }
}
