<?php

declare(strict_types=1);

namespace App\Waybills\Domain\Aggregate\Route;

use App\Shared\Infrastructure\Exception\AppException;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use Symfony\Component\Uid\Uuid;

final class RouteLog
{
    public function __construct(
        public private(set) Uuid $id,
        public readonly Vehicle $vehicle,
        public readonly ?self $previous,
        public readonly \DateTimeImmutable $date,
        public readonly Destination $destination,
        public readonly RouteLogOrigin $origin,
    ) {
        $historyStart = $vehicle->initialAt()->setTimezone($date->getTimezone());
        if ($date->format('Y-m-d') < $historyStart->format('Y-m-d')) {
            throw new AppException('Дата поездки не может быть раньше начала истории автомобиля.');
        }
        if (null !== $previous && $previous->vehicle->getId() !== $vehicle->getId()) {
            throw new AppException('Предыдущая поездка относится к другому автомобилю.');
        }
        if (null !== $previous && $previous->date > $date) {
            throw new AppException('Дата поездки не может быть раньше предыдущей.');
        }
        if ($this->departure()->samePlace($destination->location)) {
            throw new AppException('Начальная и конечная точки поездки должны различаться.');
        }

        if (null !== $previous && $previous->id->equals($id)) {
            throw new AppException('Поездка не может ссылаться на саму себя.');
        }
    }

    public function departure(): Location
    {
        if (null === $this->previous) {
            return $this->vehicle->initialLocation();
        }

        return $this->previous->destination->location;
    }

    public function assertDepartureUnchanged(Location $expected): void
    {
        if (!$this->departure()->hasSameSnapshot($expected)) {
            throw new AppException('Нельзя перестроить маршрут: изменится отправление ручной поездки. Исправьте её явно.');
        }
    }

    public function distanceInKilometers(): int
    {
        return $this->destination->distance;
    }

    public function follows(self $previous): bool
    {
        return null !== $this->previous && $this->previous->id->equals($previous->id);
    }

    public function withPrevious(?self $previous): self
    {
        return new self($this->id, $this->vehicle, $previous, $this->date, $this->destination, $this->origin);
    }
}
