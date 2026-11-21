<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\DeleteWaybill;

use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Waybills\Application\Service\AccessControl\WaybillAccessControl;
use App\Waybills\Domain\Repository\VehicleRepositoryInterface;
use App\Waybills\Domain\Repository\WaybillRepositoryInterface;
use Symfony\Component\HttpFoundation\Response;

final readonly class DeleteWaybillCommandHandler implements CommandHandlerInterface
{
    public function __construct(private WaybillRepositoryInterface $waybills, private VehicleRepositoryInterface $vehicles, private WaybillAccessControl $access)
    {
    }

    public function __invoke(DeleteWaybillCommand $command): void
    {
        $waybill = $this->waybills->findOneById($command->waybillId)
            ?? throw new AppException('Путевой лист не найден.', Response::HTTP_NOT_FOUND);
        $vehicle = $this->vehicles->findOneById($waybill->vehicleId(), forUpdate: true)
            ?? throw new AppException('Автомобиль путевого листа не найден.', Response::HTTP_NOT_FOUND);
        if (!$this->access->canEdit($vehicle)) {
            throw new ForbiddenException();
        }
        $waybill->assertCanSave($vehicle);
        $waybill->assertEditable();
        if ($this->waybills->versionOf($waybill) !== $command->expectedVersion) {
            throw new AppException('Путевой лист изменился. Обновите данные и повторите операцию.');
        }
        $this->waybills->remove($waybill);
    }
}
