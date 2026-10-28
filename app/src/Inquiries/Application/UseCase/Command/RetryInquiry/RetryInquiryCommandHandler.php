<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\RetryInquiry;

use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Infrastructure\Exception\AppException;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Перезапускает обработку запроса. Домен (requestReprocessing) переводит Failed → Received (а
 * застрявший Received оставляет как есть), чистит ответ+причину и заново поднимает InquirySubmitted —
 * дальше тот же асинхронный путь, что при первичном приёме. Инвариант допустимости — в домене.
 */
final readonly class RetryInquiryCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(RetryInquiryCommand $command): void
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($command->inquiryId);
        if (null === $inquiry) {
            throw new AppException('Запрос не найден.', Response::HTTP_NOT_FOUND);
        }

        $inquiry->requestReprocessing(new \DateTimeImmutable());
        $this->repository->add($inquiry);
    }
}
