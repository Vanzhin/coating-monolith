<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\SaveReportContent;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Service\ReportContentValidator;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Сохраняет черновик содержимого отчёта: лёгкая валидация (типы заполненных значений, неполнота ОК).
 * Строгая проверка обязательных — при отправке на проверку (submitForReview, инкремент 5).
 */
final readonly class SaveReportContentCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
        private ReportContentValidator $validator,
    ) {
    }

    public function __invoke(SaveReportContentCommand $command): void
    {
        $report = $this->repository->findOneById($command->reportId);
        if (null === $report) {
            throw new AppException('Отчёт не найден.', Response::HTTP_NOT_FOUND);
        }
        if (!$this->access->canEdit($report)) {
            throw new ForbiddenException();
        }

        $type = $report->getType();
        if (null !== $type) {
            $this->validator->validate($type, $command->content, strict: false);
        }

        $report->replaceContent($command->content, new \DateTimeImmutable());
        $this->repository->add($report);
    }
}
