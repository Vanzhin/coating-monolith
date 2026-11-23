<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateVehicle;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\VehicleAccessControl;
use App\Waybills\Application\Service\LocationCatalog;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Aggregate\Vehicle\Vehicle;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use Symfony\Component\Uid\Uuid;

final readonly class CreateVehicleCommandHandler implements CommandHandlerInterface
{
    public function __construct(private VehicleRepositoryInterface $vehicles, private VehicleAccessControl $access, private LocationCatalog $locations)
    {
    }

    public function __invoke(CreateVehicleCommand $command): string
    {
        if (!$this->access->canAccess()) {
            throw new ForbiddenException();
        }
        $vehicle = new Vehicle(
            Uuid::v7(), $command->model, new RegistrationNumber($command->registrationNumber),
            $command->initialAt, $this->locations->resolve($command->initialLocation), $command->initialOdometer,
            $command->initialFuelRemaining, $command->consumptionRate, $command->defaultFuelType,
            ...$command->fuelTypes,
        );
        $this->vehicles->add($vehicle);

        return $vehicle->getId();
    }
}
