<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Query\GetPagedInquiries;

use App\Inquiries\Application\DTO\View\InquiryAuthorView;
use App\Inquiries\Application\DTO\View\InquiryListItemView;
use App\Inquiries\Application\DTO\View\PagedInquiriesView;
use App\Inquiries\Application\Service\AccessControl\InquiryAccessControl;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Application\Query\QueryBusInterface;
use App\Shared\Application\Query\QueryHandlerInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;
use App\Shared\Infrastructure\Exception\ForbiddenException;
use App\Users\Application\UseCase\Query\GetUsersByIds\GetUsersByIdsQuery;
use App\Users\Application\UseCase\Query\GetUsersByIds\GetUsersByIdsQueryResult;

/**
 * Очередь запросов (только админ): фильтр (статус/автор/поиск/сортировка) + пагинация → лёгкие строки.
 * Подписи выбранных авторов (email) для чипов дотягиваем батчем из Users (через шину — домен чужой
 * не трогаем). Распознанные параметры/снимок в список не тащим — они на карточке.
 */
final readonly class GetPagedInquiriesQueryHandler implements QueryHandlerInterface
{
    public function __construct(
        private InquiryAccessControl $access,
        private InquiryRepositoryInterface $repository,
        private QueryBusInterface $queryBus,
    ) {
    }

    public function __invoke(GetPagedInquiriesQuery $query): PagedInquiriesView
    {
        if (!$this->access->canManage()) {
            throw new ForbiddenException();
        }

        $pager = $query->filter->pager ?? Pager::fromPage();
        $page = $this->repository->findByFilter($query->filter);

        $items = [];
        foreach ($page->items as $inquiry) {
            \assert($inquiry instanceof Inquiry);
            $items[] = $this->toItem($inquiry);
        }

        return new PagedInquiriesView(
            $items,
            $page->total,
            $pager->page,
            $pager->perPage,
            $this->resolveAuthors($query->filter->authorIds),
        );
    }

    /**
     * Выбранные id авторов → чипы с email (в порядке выбора). Пусто → нет чипов.
     *
     * @return list<InquiryAuthorView>
     */
    private function resolveAuthors(StringCollection $authorIds): array
    {
        if (0 === $authorIds->count()) {
            return [];
        }

        /** @var GetUsersByIdsQueryResult $result */
        $result = $this->queryBus->execute(new GetUsersByIdsQuery($authorIds));

        $labels = [];
        foreach ($result->users as $user) {
            $labels[$user->id] = $user->title;
        }

        $authors = [];
        foreach ($authorIds->getList() as $id) {
            $authors[] = new InquiryAuthorView($id, $labels[$id] ?? $id);
        }

        return $authors;
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
