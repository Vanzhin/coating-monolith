<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class RouteSettings
{
    /** @var list<Destination> */
    private array $destinations;

    public function __construct(public Location $base, Destination ...$destinations)
    {
        foreach ($destinations as $destination) {
            if ($base->samePlace($destination->location)) {
                throw new AppException('Направление не может совпадать с базовой точкой.');
            }
        }
        $this->destinations = $destinations;
    }

    /** @return list<Destination> */
    public function destinations(): array
    {
        return $this->destinations;
    }

    public function restoreCoordinatesForUnchangedLocation(Location $location): Location
    {
        $unchanged = $this->findUnchangedLocation($location);

        return null === $unchanged ? $location : $location->withCoordinatesFromUnchangedAddress($unchanged);
    }

    public function findUnchangedLocation(Location $location): ?Location
    {
        $matches = [];
        foreach ([$this->base, ...array_map(static fn (Destination $destination): Location => $destination->location, $this->destinations)] as $candidate) {
            if ($candidate->hasSameIdentity($location) && $candidate->equals($location)) {
                $matches[] = $candidate;
            }
        }

        $sameEnterprise = array_values(array_filter($matches, static fn (Location $candidate): bool => $candidate->enterprise === $location->enterprise));
        if ([] !== $sameEnterprise) {
            $first = $sameEnterprise[0];
            foreach ($sameEnterprise as $candidate) {
                if ($candidate->latitude !== $first->latitude || $candidate->longitude !== $first->longitude) {
                    return null;
                }
            }

            return $first;
        }

        return 1 === count($matches) ? $matches[0] : null;
    }
}
