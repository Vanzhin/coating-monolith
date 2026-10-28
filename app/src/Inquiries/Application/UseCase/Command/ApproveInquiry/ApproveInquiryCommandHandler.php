<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\ApproveInquiry;

use App\Inquiries\Application\Port\ApprovedParameterCache;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Одобряет запрос (Recognized → Approved, заморозка). После одобрения индексируем его как топливо кэша
 * (сейчас no-op; настоящая проекция — задачей кэша). Инвариант перехода статуса — в домене.
 */
final readonly class ApproveInquiryCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
        private ApprovedParameterCache $cache,
    ) {
    }

    public function __invoke(ApproveInquiryCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($command->inquiryId);
        if (null === $inquiry) {
            throw new AppException('Запрос не найден.', Response::HTTP_NOT_FOUND);
        }

        $inquiry->approve($this->access->currentUserId(), new \DateTimeImmutable());
        $this->repository->add($inquiry);

        $this->cache->index($inquiry);
    }
}
