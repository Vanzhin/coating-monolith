<?php

declare(strict_types=1);

namespace App\Waybills\Infrastructure\Mapper;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Location;
use App\Waybills\Domain\Aggregate\Route\Settlement;
use App\Waybills\Domain\Aggregate\Route\SettlementType;
use Symfony\Component\Uid\Uuid;

final class LocationFormMapper
{
    /** @return array{id: ?string, title: string, country: string, city: ?string, cityType: ?string, street: string, house: string, enterprise: ?string, latitude: ?float, longitude: ?float} */
    public function present(Location $location): array
    {
        return [
            'title' => $location->title(),
            ...$location->jsonSerialize(),
            'city' => $location->city?->name,
            'cityType' => $location->city?->type?->value,
        ];
    }

    public function fromArray(mixed $data, ?string $enterprise = null): Location
    {
        $data = is_array($data) ? $data : [];
        $id = $data['id'] ?? null;
        if (null !== $id && '' !== $id && (!is_string($id) || !Uuid::isValid($id))) {
            throw new AppException('Некорректный идентификатор точки маршрута.');
        }
        $city = Settlement::fromArray($data['city'] ?? null);
        $type = $data['cityType'] ?? null;
        $type = '' === $type ? null : $type;
        if (null !== $type && (!is_string($type) || null === SettlementType::tryFrom($type))) {
            throw new AppException('Некорректный тип населённого пункта.');
        }
        if (null !== $city && null !== $type) {
            $city = new Settlement($city->name, SettlementType::from($type));
        }

        return new Location(
            (string) ($data['country'] ?? Location::DEFAULT_COUNTRY),
            $city,
            (string) ($data['street'] ?? ''),
            (string) ($data['house'] ?? ''),
            $enterprise ?? (isset($data['enterprise']) ? (string) $data['enterprise'] : null),
            $this->coordinate($data['latitude'] ?? null),
            $this->coordinate($data['longitude'] ?? null),
            null === $id || '' === $id ? null : Uuid::fromString((string) $id),
        );
    }

    private function coordinate(mixed $value): ?float
    {
        if (null === $value || '' === $value) {
            return null;
        }
        if (!is_numeric($value) || !is_finite((float) $value)) {
            throw new AppException('Укажите корректные координаты.');
        }

        return (float) $value;
    }
}
