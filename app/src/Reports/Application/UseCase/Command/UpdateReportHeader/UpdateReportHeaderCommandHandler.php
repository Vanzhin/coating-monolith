<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\UpdateReportHeader;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Application\Service\ReportReferenceResolver;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Правка реквизитов: дата/№, ссылки (resolve id→снимок). Смена системы пере-засевает блок
 * «Система (план)». Заморозку (Approved) и владение стережёт домен + AccessControl.
 */
final readonly class UpdateReportHeaderCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
        private ReportReferenceResolver $references,
    ) {
    }

    public function __invoke(UpdateReportHeaderCommand $command): void
    {
        $report = $this->repository->findOneById($command->reportId);
        if (null === $report) {
            throw new AppException('Отчёт не найден.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canEdit($report)) {
            throw new ForbiddenException();
        }

        $now = new \DateTimeImmutable();
        $previousSystemId = $report->getSystem()?->id;

        $report->updateHeader($command->reportDate, $command->actNumber, $now);
        $system = $this->references->findSystem($command->systemId);
        $report->applyReferences(
            $this->references->resolveProject($command->projectId),
            $this->references->resolveCounterparty($command->customerId),
            $this->references->resolveCounterparty($command->contractorId),
            $this->references->systemReference($system),
            $now,
        );

        // Система сменилась → пере-засев плана (иначе оставляем прежний снимок слоёв нетронутым).
        if ($command->systemId !== $previousSystemId) {
            $content = $report->getContent();
            if (null !== $system) {
                $content['system'] = ['layers' => $this->references->seedSystemLayers($system)];
            } else {
                unset($content['system']);
            }
            $report->replaceContent($content, $now);
        }

        $this->repository->add($report);
    }
}
