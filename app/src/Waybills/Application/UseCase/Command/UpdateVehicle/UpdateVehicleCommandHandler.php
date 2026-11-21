<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\UpdateVehicle;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\VehicleAccessControl;
use App\Waybills\Domain\Aggregate\Vehicle\RegistrationNumber;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class UpdateVehicleCommandHandler implements CommandHandlerInterface
{
    public function __construct(private VehicleRepositoryInterface $vehicles, private VehicleAccessControl $access)
    {
    }

    public function __invoke(UpdateVehicleCommand $command): void
    {
        if (!$this->access->canAccess()) {
            throw new ForbiddenException();
        }
        $vehicle = $this->vehicles->findOneById($command->id, forUpdate: true)
            ?? throw new AppException('Автомобиль не найден.', Response::HTTP_NOT_FOUND);
        $vehicle->rename($command->model);
        $vehicle->changeRegistrationNumber(new RegistrationNumber($command->registrationNumber));
        $vehicle->changeFuelTypes($command->defaultFuelType, ...$command->fuelTypes);
        $vehicle->setConsumptionRate($command->consumptionRate);
        $this->vehicles->add($vehicle);
    }
}
