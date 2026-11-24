<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\FillWaybillRoute;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\ValueObject\DateTimeInterval;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Application\Service\LocationCatalog;
use App\Waybills\Application\Service\WaybillRouteBuilder;
use App\Waybills\Application\UseCase\Command\CreateWaybill\CreateWaybillCommand;
use App\Waybills\Application\UseCase\Command\UpdateWaybill\UpdateWaybillCommand;
use App\Waybills\Domain\Aggregate\Waybill\OdometerRange;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use App\Waybills\Domain\Service\Calendar\ProductionCalendar;
use App\Waybills\Domain\Service\WaybillRouteFiller;
use App\Waybills\Domain\Service\WaybillRouteFillResult;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Uid\Uuid;

final readonly class FillWaybillRouteCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private VehicleRepositoryInterface $vehicles,
        private WaybillRepositoryInterface $waybills,
        private WaybillAccessControl $access,
        private WaybillRouteBuilder $routes,
        private WaybillRouteFiller $filler,
        private ProductionCalendar $calendar,
        private LocationCatalog $locations,
    ) {
    }

    public function __invoke(FillWaybillRouteCommand $command): WaybillRouteFillResult
    {
        $source = $command->command;
        [$waybill, $odometerStart] = $this->waybill($source);
        $vehicle = $waybill->vehicle();
        $settings = $vehicle->driver()?->getRouteSettings()
            ?? throw new AppException('Для автоматического заполнения укажите водителя автомобиля.');
        $odometerEnd = $source->odometerEnd
            ?? throw new AppException('Для автоматического заполнения укажите конечный одометр.');

        $trips = $this->locations->restoreTripCoordinates($waybill, $settings, ...$source->trips);
        $fixedRoute = $this->routes->buildFixed($waybill, $this->waybills->findPredecessor($waybill), $source->routePresent, ...$trips);
        if (null === $fixedRoute) {
            throw new AppException('Для автоматического заполнения укажите маршрут.');
        }

        return $this->filler->fill(
            $settings,
            $source->period,
            $fixedRoute,
            new OdometerRange($odometerStart, $odometerEnd),
            $this->calendar,
            ...$source->absences,
        );
    }

    /** @return array{Waybill, int} */
    private function waybill(CreateWaybillCommand|UpdateWaybillCommand $command): array
    {
        if ($command instanceof UpdateWaybillCommand) {
            $waybill = $this->waybills->findOneById($command->waybillId)
                ?? throw new AppException('Путевой лист не найден.', Response::HTTP_NOT_FOUND);
            $vehicle = $this->vehicles->findOneById($waybill->vehicleId())
                ?? throw new AppException('Автомобиль путевого листа не найден.', Response::HTTP_NOT_FOUND);
            if (!$this->access->canEdit($vehicle)) {
                throw new ForbiddenException();
            }
            $waybill->assertCanSave($vehicle);
            $waybill->assertEditable();
            if ($this->waybills->versionOf($waybill) !== $command->expectedVersion) {
                throw new AppException('Путевой лист изменился. Обновите данные и повторите операцию.');
            }

            return [$waybill, $waybill->odometerStart()];
        }

        if (!$this->access->canCreate()) {
            throw new ForbiddenException();
        }
        $vehicle = $this->vehicles->findOneById($command->vehicleId)
            ?? throw new AppException('Автомобиль не найден.', Response::HTTP_NOT_FOUND);
        if (!$this->access->canCreateFor($vehicle)) {
            throw new ForbiddenException();
        }
        $waybill = Waybill::createDraft(Uuid::v7(), 1, $vehicle, new DateTimeInterval($command->period->from(), $command->period->to()));
        $waybill->assertInitialReadingsMatchEntered($command->expectedOdometerStart, $command->expectedFuelStart);

        return [$waybill, $vehicle->currentOdometer()];
    }
}
