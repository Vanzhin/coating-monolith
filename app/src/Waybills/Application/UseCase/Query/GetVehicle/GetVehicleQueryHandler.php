<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetVehicle;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\DTO\VehicleDTO;
use App\Waybills\Application\Service\AccessControl\VehicleAccessControl;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Domain\Aggregate\Vehicle\FuelType;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final readonly class GetVehicleQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private VehicleRepositoryInterface $vehicles,
        private VehicleAccessControl $access,
        private WaybillRepositoryInterface $waybills,
        private WaybillAccessControl $waybillAccess,
    ) {
    }

    public function __invoke(GetVehicleQuery $query): VehicleDTO
    {
        if (!$this->access->canAccess()) {
            throw new ForbiddenException();
        }
        $vehicle = Uuid::isValid($query->id) ? $this->vehicles->findOneById($query->id) : null;
        if (null === $vehicle) {
            throw new AppException('Автомобиль не найден.', Response::HTTP_NOT_FOUND);
        }

        $routeStartLocation = null;
        if ($query->forWaybillCreation && $this->waybillAccess->canCreateFor($vehicle)) {
            $routeStartLocation = $this->waybills->findPredecessor($vehicle)?->destination->location;
        }

        return new VehicleDTO(
            $vehicle->getId(), $vehicle->getModel(),
            $vehicle->getRegistrationNumber()->value(),
            array_map(static fn (FuelType $fuelType): string => $fuelType->value, $vehicle->getFuelTypes()),
            $vehicle->getConsumptionRate()?->value(),
            $vehicle->currentOdometer(), (string) $vehicle->fuelRemaining(), $vehicle->initialLocation(),
            $vehicle->getDefaultFuelType()->value, null !== $vehicle->driver(), $vehicle->driver()?->getDriverDetails()->fullName,
            $routeStartLocation,
        );
    }
}
