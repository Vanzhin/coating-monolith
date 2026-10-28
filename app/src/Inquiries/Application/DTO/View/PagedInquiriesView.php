<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Страница очереди запросов: строки + всего + текущая страница/размер (для пагинации) + выбранные
 * авторы с подписями (для чипов фильтра — резолв email батчем в хендлере).
 */
final readonly class PagedInquiriesView
{
    /**
     * @param list<InquiryListItemView> $items
     * @param list<InquiryAuthorView>   $authors выбранные авторы (чипы), в порядке выбора
     */
    public function __construct(
        public array $items,
        public int $total,
        public int $page,
        public int $perPage,
        public array $authors = [],
    ) {
    }

    public function pages(): int
    {
        return $this->perPage > 0 ? (int) ceil($this->total / $this->perPage) : 1;
    }
}
