<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetInquiryStatus;

use App\Inquiries\Application\DTO\View\InquiryStatusView;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Лёгкий статус запроса для поллинга карточки (только админ). Не тянет параметры/вложения — опрос
 * частый. «В работе» решает домен (InquiryStatus::isProcessing).
 */
final readonly class GetInquiryStatusQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(GetInquiryStatusQuery $query): ?InquiryStatusView
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $inquiry = $this->repository->findOneById($query->inquiryId);
        if (null === $inquiry) {
            return null;
        }

        $status = $inquiry->getStatus();

        return new InquiryStatusView($status->value, $status->isProcessing());
    }
}
