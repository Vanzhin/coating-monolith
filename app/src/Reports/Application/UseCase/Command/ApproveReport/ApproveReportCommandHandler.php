<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\ApproveReport;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Утверждение отчёта ревьюером/админом (На проверке → Утверждён, заморозка). Дальнейшие правки
 * запрещены доменом (Report::isEditable). Файл не храним — акт воспроизводится из замороженных данных (Вариант A).
 */
final readonly class ApproveReportCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
    ) {
    }

    public function __invoke(ApproveReportCommand $command): void
    {
        $report = $this->repository->findOneById($command->reportId);
        if (null === $report) {
            throw new AppException('Отчёт не найден.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canReview($report)) {
            throw new ForbiddenException();
        }

        $report->approve(new \DateTimeImmutable());
        $this->repository->add($report);
    }
}
