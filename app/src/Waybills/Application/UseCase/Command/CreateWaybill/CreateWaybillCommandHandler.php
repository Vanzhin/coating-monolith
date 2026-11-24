<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\CreateWaybill;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Application\Service\LocationCatalog;
use App\Waybills\Application\Service\RefuelingOperationBuilder;
use App\Waybills\Application\Service\WaybillRouteBuilder;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final readonly class CreateWaybillCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private VehicleRepositoryInterface $vehicles,
        private WaybillRepositoryInterface $waybills,
        private WaybillAccessControl $access,
        private WaybillRouteBuilder $routes,
        private RefuelingOperationBuilder $refuelings,
        private LocationCatalog $locations,
    ) {
    }

    public function __invoke(CreateWaybillCommand $command): string
    {
        if (!$this->access->canCreate()) {
            throw new ForbiddenException();
        }
        $vehicle = $this->vehicles->findOneById($command->vehicleId, forUpdate: true)
            ?? throw new AppException('Автомобиль не найден.', Response::HTTP_NOT_FOUND);
        if (!$this->access->canCreateFor($vehicle)) {
            throw new ForbiddenException();
        }
        $number = $this->waybills->nextNumber($this->access->currentUserId());
        $waybill = Waybill::createDraft(Uuid::v7(), $number, $vehicle, new DateTimeInterval($command->period->from(), $command->period->to()));
        $waybill->assertInitialReadingsMatchEntered($command->expectedOdometerStart, $command->expectedFuelStart);
        $trips = $this->locations->resolveTrips($waybill, ...$command->trips);
        $route = $this->routes->build($waybill, $this->waybills->findPredecessor($waybill), $command->routePresent, ...$trips);
        $operations = $this->refuelings->build($waybill, $command->refuelings);
        $waybill->reviseDraftWithRefuelingOperations($command->period, $command->odometerEnd, $command->fuel, $route, $operations, ...$command->absences);
        $this->waybills->add($waybill);

        return $waybill->getId();
    }
}
