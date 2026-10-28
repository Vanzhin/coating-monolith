<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Repository;

use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Фильтр очереди запросов — bag-of-fields без логики. Оси: статус, автор (ownerId, OR внутри),
 * поиск по телу запроса (ILIKE raw_text), сортировка по дате + пагинация. Новые оси добавляются
 * ПОЛЕМ сюда, а не новым методом репозитория (конвенция findByFilter).
 */
final class InquiriesFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?InquiryStatus $status = null,
        public StringCollection $authorIds = new StringCollection(),
        public ?string $search = null,
        public InquiriesSort $sort = InquiriesSort::DEFAULT,
    ) {
    }
}
