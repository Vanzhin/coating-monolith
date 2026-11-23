<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Location;

use App\Shared\Domain\Aggregate\Aggregate;
use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Route\Location as LocationSnapshot;
use App\Waybills\Domain\Aggregate\Route\Settlement;
use App\Waybills\Domain\Aggregate\Route\SettlementType;
use Symfony\Component\Uid\Uuid;

class Location extends Aggregate
{
    private string $country;
    private string $city;
    private ?SettlementType $cityType = null;
    private string $street;
    private string $house;
    private ?string $enterprise = null;
    private ?float $latitude = null;
    private ?float $longitude = null;
    private \DateTimeImmutable $createdAt;
    private \DateTimeImmutable $updatedAt;

    public function __construct(
        private readonly Uuid $id,
        private readonly string $ownerId,
        LocationSnapshot $address,
        \DateTimeImmutable $now,
    ) {
        if ('' === trim($ownerId)) {
            throw new AppException('Укажите владельца точки маршрута.');
        }
        $this->apply($address);
        $this->createdAt = $now;
        $this->updatedAt = $now;
    }

    public function change(LocationSnapshot $address, \DateTimeImmutable $now): void
    {
        $this->apply($address);
        $this->updatedAt = $now;
    }

    public function snapshot(): LocationSnapshot
    {
        $city = '' === $this->city ? null : new Settlement($this->city, $this->cityType);

        return new LocationSnapshot($this->country, $city, $this->street, $this->house, $this->enterprise, $this->latitude, $this->longitude, $this->id);
    }

    public function isOwnedBy(string $ownerId): bool
    {
        return $this->ownerId === $ownerId;
    }

    public function getId(): string
    {
        return (string) $this->id;
    }

    public function createdAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function updatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function apply(LocationSnapshot $address): void
    {
        $this->country = $address->country;
        $this->city = null === $address->city ? '' : $address->city->name;
        $this->cityType = $address->city?->type;
        $this->street = $address->street;
        $this->house = $address->house;
        $this->enterprise = $address->enterprise;
        $this->latitude = $address->latitude;
        $this->longitude = $address->longitude;
    }
}
