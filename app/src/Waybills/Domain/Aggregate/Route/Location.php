<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;

final readonly class Location implements \JsonSerializable
{
    public const string DEFAULT_COUNTRY = 'Россия';

    public string $name;
    public string $country;
    public string $city;
    public string $street;
    public string $house;
    public ?float $latitude;
    public ?float $longitude;

    public function __construct(string $country = self::DEFAULT_COUNTRY, string $city = '', string $street = '', string $house = '')
    {
        $country = trim($country);
        $city = trim($city);
        $street = trim($street);
        $house = trim($house);
        if (1 === preg_match('/\A[\s\p{Z}]*\z/u', $country.$city.$street.$house)) {
            throw new AppException('Укажите адрес точки маршрута.');
        }

        $this->country = $country;
        $this->city = $city;
        $this->street = $street;
        $this->house = $house;
        $this->name = implode(', ', array_filter([$country, $city, $street, $house], static fn (string $part): bool => '' !== $part));
        $this->latitude = null;
        $this->longitude = null;
    }

    public function equals(self $other): bool
    {
        return $this->country === $other->country
            && $this->city === $other->city
            && $this->street === $other->street
            && $this->house === $other->house;
    }

    public function toString(): string
    {
        return $this->name;
    }

    /** @return array{country: string, city: string, street: string, house: string} */
    public function jsonSerialize(): array
    {
        return ['country' => $this->country, 'city' => $this->city, 'street' => $this->street, 'house' => $this->house];
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        foreach (['country', 'city', 'street', 'house'] as $field) {
            if (!isset($data[$field]) || !is_string($data[$field])) {
                throw new AppException('Некорректные данные адреса точки маршрута.');
            }
        }

        return new self($data['country'], $data['city'], $data['street'], $data['house']);
    }
}
