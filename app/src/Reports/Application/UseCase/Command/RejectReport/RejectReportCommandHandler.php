<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\RejectReport;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отклонение отчёта ревьюером/админом (На проверке → Отклонён) с причиной для автора. Автор потом
 * снова сохраняет черновик — отчёт возвращается «в работу», причина снимается.
 */
final readonly class RejectReportCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
    ) {
    }

    public function __invoke(RejectReportCommand $command): void
    {
        $report = $this->repository->findOneById($command->reportId);
        if (null === $report) {
            throw new AppException('Отчёт не найден.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canReview($report)) {
            throw new ForbiddenException();
        }

        $report->reject($command->reason, new \DateTimeImmutable());
        $this->repository->add($report);
    }
}
