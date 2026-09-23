<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateReport;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Application\Service\ReportReferenceResolver;
use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Заводит отчёт: владелец — текущий актор (owner-based). Ссылки резолвятся в снимки {id,title};
 * слои выбранной системы засеваются в блок «Система (план)».
 */
final readonly class CreateReportCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
        private ReportReferenceResolver $references,
    ) {
    }

    public function __invoke(CreateReportCommand $command): CreateReportCommandResult
    {
        $now = new \DateTimeImmutable();
        $report = new Report(
            Uuid::v7(),
            $this->access->currentUserId(),
            $command->type,
            $now,
            $command->reportDate,
            $command->actNumber,
            $command->address,
            $command->workPeriod,
        );
        $system = $this->references->findSystem($command->systemId);
        $report->applyReferences(
            $this->references->resolveProject($command->projectId),
            $this->references->resolveCounterparty($command->customerId),
            $this->references->resolveCounterparty($command->contractorId),
            $this->references->systemReference($system),
            $now,
        );
        $report->replaceContent(['system' => [
            'layers' => $this->references->seedSystemLayers($system),
            'dft_nominal_total' => $system->totalDft, // номинальная ТСП покрытия — доменный расчёт системы (из DTO), замораживаем снимком
        ]], $now);
        $this->repository->add($report);

        return new CreateReportCommandResult($report->getId());
    }
}
