<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use Symfony\Component\Uid\Uuid;

final readonly class Location implements \JsonSerializable
{
    public const string DEFAULT_COUNTRY = 'Россия';

    public string $name;
    public string $country;
    public ?Settlement $city;
    public string $street;
    public string $house;
    public ?string $enterprise;

    public function __construct(
        string $country = self::DEFAULT_COUNTRY,
        Settlement|string|null $city = '',
        string $street = '',
        string $house = '',
        ?string $enterprise = null,
        public ?float $latitude = null,
        public ?float $longitude = null,
        public ?Uuid $id = null,
    ) {
        $country = trim($country);
        $city = is_string($city) || null === $city ? Settlement::fromArray($city) : $city;
        $street = trim($street);
        $house = trim($house);
        if (1 === preg_match('/\A[\s\p{Z}]*\z/u', $country.(null === $city ? '' : $city->name).$street.$house)) {
            throw new AppException('Укажите адрес точки маршрута.');
        }
        if ((null === $latitude) !== (null === $longitude)) {
            throw new AppException('Укажите и широту, и долготу.');
        }
        if (null !== $latitude && null !== $longitude && (!is_finite($latitude) || !is_finite($longitude))) {
            throw new AppException('Координаты должны быть конечными числами.');
        }
        if (null !== $latitude && ($latitude < -90 || $latitude > 90)) {
            throw new AppException('Широта должна находиться в диапазоне от -90 до 90 градусов.');
        }
        if (null !== $longitude && ($longitude < -180 || $longitude > 180)) {
            throw new AppException('Долгота должна находиться в диапазоне от -180 до 180 градусов.');
        }

        $enterprise = null === $enterprise ? null : trim($enterprise);
        $this->country = $country;
        $this->city = $city;
        $this->street = $street;
        $this->house = $house;
        $this->enterprise = '' === $enterprise ? null : $enterprise;
        $this->name = implode(', ', array_filter([$country, $city?->toString(), $street, $house], static fn (?string $part): bool => null !== $part && '' !== $part));
    }

    public function equals(self $other): bool
    {
        return $this->country === $other->country
            && (null === $this->city ? null === $other->city : $this->city->equals($other->city))
            && $this->street === $other->street
            && $this->house === $other->house;
    }

    public function samePlace(self $other): bool
    {
        if (null !== $this->id && null !== $other->id) {
            return $this->id->equals($other->id);
        }

        return $this->equals($other);
    }

    public function withId(?Uuid $id): self
    {
        return new self($this->country, $this->city, $this->street, $this->house, $this->enterprise, $this->latitude, $this->longitude, $id);
    }

    public function withEnterprise(?string $enterprise): self
    {
        return new self($this->country, $this->city, $this->street, $this->house, $enterprise, $this->latitude, $this->longitude, $this->id);
    }

    public function toString(): string
    {
        return $this->name;
    }

    public function title(): string
    {
        return null === $this->enterprise ? $this->name : $this->name.', '.$this->enterprise;
    }

    /** @return array{id: ?string, country: string, city: ?array{name: string, type: ?string}, street: string, house: string, enterprise: ?string, latitude: ?float, longitude: ?float} */
    public function jsonSerialize(): array
    {
        return [
            'id' => null === $this->id ? null : (string) $this->id,
            'country' => $this->country,
            'city' => $this->city?->jsonSerialize(),
            'street' => $this->street,
            'house' => $this->house,
            'enterprise' => $this->enterprise,
            'latitude' => $this->latitude,
            'longitude' => $this->longitude,
        ];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['country', 'street', 'house'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new AppException('Некорректные данные адреса точки маршрута.');
            }
        }
        $id = $data['id'] ?? null;
        if (null !== $id && (!is_string($id) || !Uuid::isValid($id))) {
            throw new AppException('Некорректный идентификатор точки маршрута.');
        }
        $enterprise = $data['enterprise'] ?? null;
        if (null !== $enterprise && !is_string($enterprise)) {
            throw new AppException('Некорректное предприятие точки маршрута.');
        }
        foreach (['latitude', 'longitude'] as $field) {
            if (isset($data[$field]) && !is_int($data[$field]) && !is_float($data[$field])) {
                throw new AppException('Некорректные координаты точки маршрута.');
            }
        }

        return new self(
            $data['country'],
            Settlement::fromArray($data['city'] ?? null),
            $data['street'],
            $data['house'],
            $enterprise,
            isset($data['latitude']) ? (float) $data['latitude'] : null,
            isset($data['longitude']) ? (float) $data['longitude'] : null,
            null === $id ? null : Uuid::fromString($id),
        );
    }
}
