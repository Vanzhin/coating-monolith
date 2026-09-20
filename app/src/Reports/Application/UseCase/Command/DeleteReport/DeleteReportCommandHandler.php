<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\DeleteReport;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Удаление отчёта: владелец или админ (canEdit). Утверждённый отчёт иммутабелен — домен не даст
 * (assertDeletable). Объект грузим один раз и отдаём в проверку (без TOCTOU/двойного фетча).
 */
final readonly class DeleteReportCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
    ) {
    }

    public function __invoke(DeleteReportCommand $command): void
    {
        $report = $this->repository->findOneById($command->reportId);
        if (null === $report) {
            throw new AppException('Отчёт не найден.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canEdit($report)) {
            throw new ForbiddenException();
        }
        $report->assertDeletable();

        $this->repository->remove($report);
    }
}
