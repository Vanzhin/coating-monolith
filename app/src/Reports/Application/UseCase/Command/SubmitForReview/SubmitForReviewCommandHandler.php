<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\SubmitForReview;

use App\Reports\Application\Service\AccessControl\ReportAccessControl;
use App\Reports\Domain\Repository\ReportRepositoryInterface;
use App\Reports\Domain\Service\ReportContentValidator;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Отправка отчёта на проверку (В работе → На проверке). Тут включается СТРОГАЯ валидация: все
 * обязательные поля блоков должны быть заполнены — иначе AppException и отчёт не уходит.
 */
final readonly class SubmitForReviewCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private ReportRepositoryInterface $repository,
        private ReportAccessControl $access,
        private ReportContentValidator $validator,
    ) {
    }

    public function __invoke(SubmitForReviewCommand $command): void
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
            $this->validator->validate($type, $report->getContent(), strict: true);
        }

        $report->submitForReview(new \DateTimeImmutable());
        $this->repository->add($report);
    }
}
