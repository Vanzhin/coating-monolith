<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetPagedInquiries;

use App\Inquiries\Application\DTO\View\InquiryListItemView;
use App\Inquiries\Application\DTO\View\PagedInquiriesView;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Repository\InquiriesFilter;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\ForbiddenException;

/**
 * Очередь запросов (только админ): фильтр по статусу + пагинация → лёгкие строки. Распознанные
 * параметры/снимок в список не тащим — они на карточке.
 */
final readonly class GetPagedInquiriesQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
    ) {
    }

    public function __invoke(GetPagedInquiriesQuery $query): PagedInquiriesView
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $filter = new InquiriesFilter(
            Pager::fromPage($query->page, $query->perPage),
            null !== $query->status ? InquiryStatus::tryFrom($query->status) : null,
        );
        $page = $this->repository->findByFilter($filter);

        $items = [];
        foreach ($page->items as $inquiry) {
            \assert($inquiry instanceof Inquiry);
            $items[] = $this->toItem($inquiry);
        }

        return new PagedInquiriesView($items, $page->total, $query->page, $query->perPage);
    }

    private function toItem(Inquiry $inquiry): InquiryListItemView
    {
        $raw = trim($inquiry->getRawText());
        $summary = '' !== $raw
            ? $this->shorten($raw, 90)
            : sprintf('Вложений: %d', $inquiry->getAttachmentFileIds()->count());

        return new InquiryListItemView(
            id: $inquiry->getId(),
            typeLabel: $inquiry->getType()->label(),
            statusValue: $inquiry->getStatus()->value,
            statusLabel: $inquiry->getStatus()->label(),
            summary: $summary,
            answerTotal: $inquiry->getAnswer()?->total,
            createdAt: $inquiry->getCreatedAt(),
        );
    }

    private function shorten(string $text, int $max): string
    {
        return mb_strlen($text) > $max ? mb_substr($text, 0, $max).'…' : $text;
    }
}
