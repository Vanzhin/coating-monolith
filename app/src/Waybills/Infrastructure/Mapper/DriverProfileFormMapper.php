<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Mapper;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Application\UseCase\Query\GetOwnDriverProfile\GetOwnDriverProfileQueryResult;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverDetails;
use App\Waybills\Domain\Aggregate\Route\Destination;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\RouteSettings;

final class DriverProfileFormMapper
{
    /** @return array{fullName: string, licenseNumber: string, licenseIssuedAt: string, snils: string, base: array<string, string>, destinations: list<array{location: array<string, string>, distance: int, enterprise: ?string}>} */
    public function fromProfile(GetOwnDriverProfileQueryResult $profile): array
    {
        return [
            'fullName' => $profile->driverDetails->fullName ?? '',
            'licenseNumber' => $profile->driverDetails?->licenseNumber->formatted() ?? '',
            'licenseIssuedAt' => $profile->driverDetails?->licenseIssuedAt->format('Y-m-d') ?? '',
            'snils' => $profile->driverDetails?->snils->formatted() ?? '',
            'base' => $profile->routeSettings?->base->jsonSerialize() ?? (new Location())->jsonSerialize(),
            'destinations' => array_map(static fn (Destination $destination) => [
                'location' => $destination->location->jsonSerialize(),
                'distance' => $destination->distance,
                'enterprise' => $destination->enterprise,
            ], $profile->routeSettings?->destinations() ?? []),
        ];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{DriverDetails, RouteSettings}
     */
    public function toDomain(array $data, GetOwnDriverProfileQueryResult $profile): array
    {
        $dateText = (string) ($data['licenseIssuedAt'] ?? '');
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $dateText);
        if (false === $date || $date->format('Y-m-d') !== $dateText) {
            throw new AppException('Укажите корректную дату выдачи удостоверения.');
        }
        if ($profile->driverDetails?->licenseIssuedAt->format('Y-m-d') === $dateText) {
            $date = $profile->driverDetails->licenseIssuedAt;
        }
        $destinations = [];
        foreach ($data['destinations'] ?? [] as $row) {
            $destinations[] = new Destination(
                $this->location($row['location'] ?? []),
                $this->integer($row['distance'] ?? ''),
                isset($row['enterprise']) ? (string) $row['enterprise'] : null,
            );
        }

        return [
            new DriverDetails((string) ($data['fullName'] ?? ''), (string) ($data['licenseNumber'] ?? ''), $date, (string) ($data['snils'] ?? '')),
            new RouteSettings($this->location($data['base'] ?? []), ...$destinations),
        ];
    }

    private function location(mixed $data): Location
    {
        $data = is_array($data) ? $data : [];

        return new Location(
            (string) ($data['country'] ?? Location::DEFAULT_COUNTRY),
            (string) ($data['city'] ?? ''),
            (string) ($data['street'] ?? ''),
            (string) ($data['house'] ?? ''),
        );
    }

    private function integer(mixed $value): int
    {
        if (is_string($value)) {
            $value = trim($value);
        }
        if ((!is_string($value) && !is_int($value)) || false === filter_var($value, FILTER_VALIDATE_INT)) {
            throw new AppException('Укажите целое число километров для расстояния.');
        }

        return (int) $value;
    }
}
