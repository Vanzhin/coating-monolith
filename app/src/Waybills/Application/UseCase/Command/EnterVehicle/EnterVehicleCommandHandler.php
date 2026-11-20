<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\EnterVehicle;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\VehicleAccessControl;
use App\Waybills\Domain\Aggregate\DriverProfile\DriverProfile;
use App\Waybills\Domain\Repository\DriverProfileRepositoryInterface;
use App\Waybills\Domain\Repository\ProfileFilter;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnterVehicleCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private DriverProfileRepositoryInterface $profiles,
        private VehicleRepositoryInterface $vehicles,
        private VehicleAccessControl $access,
    ) {
    }

    public function __invoke(EnterVehicleCommand $command): void
    {
        if (!$this->access->canAccess()) {
            throw new ForbiddenException();
        }
        $profile = $this->profiles->findByFilter(new ProfileFilter($this->access->currentUserId(), Pager::fromPage(1, 1)))->items[0] ?? null;
        if (!$profile instanceof DriverProfile) {
            throw new AppException('Карточка водителя не найдена.', Response::HTTP_NOT_FOUND);
        }
        $vehicle = $this->vehicles->findOneById($command->vehicleId, forUpdate: true)
            ?? throw new AppException('Автомобиль не найден.', Response::HTTP_NOT_FOUND);
        $vehicle->enter($profile);
        $this->vehicles->add($vehicle);
    }
}
