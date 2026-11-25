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
use App\Waybills\Domain\Aggregate\Route\RouteLog;
use App\Waybills\Domain\Aggregate\Route\RouteLogOrigin;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
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
        return $this->resolveAddress($address, inferMissingCoordinates: true);
    }

    public function resolveExact(Location $address): Location
    {
        return $this->resolveAddress($address, inferMissingCoordinates: false);
    }

    private function resolveAddress(Location $address, bool $inferMissingCoordinates): Location
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
            if ($inferMissingCoordinates) {
                $address = $address->withCoordinatesFromUnchangedAddress($current);
            }
            if ($this->sameAddress($current, $address)) {
                return $current;
            }

            return $this->resolveAddress($address->withId(null), $inferMissingCoordinates);
        }

        $ownerId = $this->access->currentUserId();
        $existing = $this->locations->findByFilter(new LocationFilter(ownerId: $ownerId));
        $matchingAddresses = [];
        foreach ($existing->items as $location) {
            $snapshot = $location->snapshot();
            if ((!$inferMissingCoordinates || $address->hasCoordinates()) && $this->sameAddress($snapshot, $address)) {
                return $snapshot;
            }
            if ($inferMissingCoordinates && !$address->hasCoordinates() && $snapshot->hasSameDescription($address)) {
                $matchingAddresses[] = $snapshot;
            }
        }
        if (1 === count($matchingAddresses)) {
            return $matchingAddresses[0];
        }

        $location = new CatalogLocation(Uuid::v7(), $ownerId, $address, new \DateTimeImmutable());
        $this->locations->add($location);

        return $location->snapshot();
    }

    public function synchronizeSettings(RouteSettings $settings, ?RouteSettings $previous = null): RouteSettings
    {
        $destinations = [];
        foreach ($settings->destinations() as $destination) {
            $destinations[] = new Destination($this->synchronize($destination->location, $previous), $destination->distance);
        }

        return new RouteSettings($this->synchronize($settings->base, $previous), ...$destinations);
    }

    /** @return list<TripInput> */
    public function resolveTrips(?Waybill $waybill, ?RouteLog $previous, TripInput ...$trips): array
    {
        $trips = $this->restoreTripCoordinates($waybill, $waybill?->vehicle()->driver()?->getRouteSettings(), $previous, ...$trips);
        $originals = [];
        foreach ($waybill?->trips() ?? [] as $trip) {
            $originals[(string) $trip->id] = $trip->destination->location;
        }

        return array_map(function (TripInput $trip) use ($originals, $waybill, $previous): TripInput {
            $address = $trip->destination->location;
            $original = null === $trip->id ? null : ($originals[(string) $trip->id] ?? null);
            if (null !== $original && $original->hasSameIdentity($address) && $original->hasSameDescription($address)) {
                $location = $this->sameAddress($original, $address) ? $original : $this->resolveAddress($address->withId(null), inferMissingCoordinates: false);
            } elseif (null !== ($known = $this->findRouteLocation($waybill, $previous, $address)) && $this->sameAddress($known, $address)) {
                $location = $known;
            } else {
                $location = $this->resolve($address);
            }

            return $this->withTripLocation($trip, $location);
        }, $trips);
    }

    /** @return list<TripInput> */
    public function restoreTripCoordinates(?Waybill $waybill, ?RouteSettings $settings, ?RouteLog $previous, TripInput ...$trips): array
    {
        $originals = [];
        foreach ($waybill?->trips() ?? [] as $trip) {
            $originals[(string) $trip->id] = $trip->destination->location;
        }

        return array_map(function (TripInput $trip) use ($settings, $originals, $waybill, $previous): TripInput {
            $address = $trip->destination->location;
            $original = null === $trip->id ? null : ($originals[(string) $trip->id] ?? null);
            if (null !== $original && $original->hasSameIdentity($address) && $original->hasSameDescription($address)) {
                return $trip;
            }
            $known = $this->findRouteLocation($waybill, $previous, $address);
            if (null !== $known) {
                return $this->withTripLocation($trip, $address->withCoordinatesFromUnchangedAddress($known));
            }

            if (null === $address->id) {
                if (null === $settings || (RouteLogOrigin::Automatic !== $trip->origin && null === $trip->originalDestination)) {
                    return $trip;
                }

                return $this->withTripLocation($trip, $settings->restoreCoordinatesForUnchangedLocation($address));
            }
            $location = $this->findAccessibleLocation($address->id);

            return $this->withTripLocation($trip, $address->withCoordinatesFromUnchangedAddress($location->snapshot()));
        }, $trips);
    }

    private function withTripLocation(TripInput $trip, Location $location): TripInput
    {
        $original = $trip->originalDestination;
        if (null !== $original && $this->sameAddress($original->location, $location) && $original->location->samePlace($location)) {
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
    }

    private function findAccessibleLocation(Uuid $id): CatalogLocation
    {
        $location = $this->locations->findOneById((string) $id)
            ?? throw new AppException('Точка маршрута не найдена.');
        if (!$this->access->canEdit($location)) {
            throw new ForbiddenException();
        }

        return $location;
    }

    private function findRouteLocation(?Waybill $waybill, ?RouteLog $previous, Location $address): ?Location
    {
        $candidates = [];
        if (null !== $previous) {
            $candidates[] = $previous->destination->location;
        }
        if (null !== $waybill) {
            $candidates[] = $waybill->vehicle()->initialLocation();
            foreach ($waybill->trips() as $trip) {
                $candidates[] = $trip->destination->location;
            }
        }
        $match = null;
        foreach ($candidates as $candidate) {
            if (!$candidate->hasSameIdentity($address) || !$candidate->hasSameDescription($address)) {
                continue;
            }
            if ($address->hasCoordinates() && !$address->hasSameCoordinates($candidate)) {
                continue;
            }
            if (null !== $match && !$match->hasSameCoordinates($candidate)) {
                return null;
            }
            $match = $candidate;
        }

        return $match;
    }

    private function synchronize(Location $address, ?RouteSettings $previous = null): Location
    {
        if (null === $address->id) {
            $address = $previous?->restoreCoordinatesForUnchangedLocation($address) ?? $address;

            return $this->resolve($address);
        }
        $location = $this->locations->findOneById((string) $address->id);
        if (null === $location) {
            throw new AppException('Точка маршрута не найдена.');
        }
        if (!$this->access->canEdit($location)) {
            throw new ForbiddenException();
        }
        $address = $address->withCoordinatesFromUnchangedAddress($location->snapshot());
        $address = $previous?->restoreCoordinatesForUnchangedLocation($address) ?? $address;
        $location->change($address, new \DateTimeImmutable());

        return $location->snapshot();
    }

    private function sameAddress(Location $left, Location $right): bool
    {
        return $left->hasSameDescription($right) && $left->hasSameCoordinates($right);
    }
}
