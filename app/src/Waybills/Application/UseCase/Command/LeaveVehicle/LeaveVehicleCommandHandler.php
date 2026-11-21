<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\LeaveVehicle;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\VehicleAccessControl;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class LeaveVehicleCommandHandler implements CommandHandlerInterface
{
    public function __construct(private VehicleRepositoryInterface $vehicles, private VehicleAccessControl $access)
    {
    }

    public function __invoke(LeaveVehicleCommand $command): void
    {
        if (!$this->access->canAccess()) {
            throw new ForbiddenException();
        }
        $vehicle = $this->vehicles->findOneById($command->vehicleId, forUpdate: true)
            ?? throw new AppException('Автомобиль не найден.', Response::HTTP_NOT_FOUND);
        if (!$this->access->canLeave($vehicle)) {
            throw new ForbiddenException();
        }
        $driver = $vehicle->driver();
        if (null === $driver) {
            throw new \LogicException('В автомобиле должен быть водитель.');
        }
        $vehicle->leave($driver);
        $this->vehicles->add($vehicle);
    }
}
