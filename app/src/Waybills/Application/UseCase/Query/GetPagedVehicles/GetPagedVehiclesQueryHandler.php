<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetPagedVehicles;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\PaginationResult;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\DTO\VehicleListItem;
use App\Waybills\Application\Service\AccessControl\VehicleAccessControl;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Repository\VehicleFilter;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;

final readonly class GetPagedVehiclesQueryHandler implements QueryHandlerInterface
{
    public function __construct(private VehicleRepositoryInterface $vehicles, private VehicleAccessControl $access)
    {
    }

    public function __invoke(GetPagedVehiclesQuery $query): PaginationResult
    {
        if (!$this->access->canAccess()) {
            throw new ForbiddenException();
        }

        $filter = $query->filter;
        if ($query->currentDriverOnly) {
            $filter = new VehicleFilter($filter->search, $filter->pager, $this->access->currentUserId());
        }
        $result = $this->vehicles->findByFilter($filter);

        return new PaginationResult(array_map(fn (Vehicle $vehicle): VehicleListItem => new VehicleListItem(
            $vehicle->getId(),
            $vehicle->getModel(),
            $vehicle->getRegistrationNumber()->value(),
            array_map(static fn (FuelType $fuelType): string => $fuelType->value, $vehicle->getFuelTypes()),
            $vehicle->currentOdometer(),
            (string) $vehicle->fuelRemaining(),
            null !== $vehicle->driver(),
            $this->access->canLeave($vehicle),
        ), $result->items), $result->total);
    }
}
