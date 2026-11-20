<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Query\GetWaybill;

use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Domain\Aggregate\Waybill\Waybill;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class GetWaybillQueryHandler implements QueryHandlerInterface
{
    public function __construct(private WaybillRepositoryInterface $waybills, private VehicleRepositoryInterface $vehicles, private WaybillAccessControl $access)
    {
    }

    public function __invoke(GetWaybillQuery $query): Waybill
    {
        $waybill = $this->waybills->findOneById($query->waybillId)
            ?? throw new AppException('Путевой лист не найден.', Response::HTTP_NOT_FOUND);
        $vehicle = $this->vehicles->findOneById($waybill->vehicleId())
            ?? throw new AppException('Автомобиль путевого листа не найден.', Response::HTTP_NOT_FOUND);
        if (!$this->access->canEdit($vehicle)) {
            throw new ForbiddenException();
        }

        return $waybill;
    }
}
