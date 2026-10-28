<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Repository;

use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Shared\Domain\Repository\Pager;

/**
 * Фильтр очереди запросов — bag-of-fields без логики. Пока статус + пагинация; новые оси (тип,
 * владелец, поиск) добавляются ПОЛЕМ сюда, а не новым методом репозитория (конвенция findByFilter).
 */
final class InquiriesFilter
{
    public function __construct(
        public ?Pager $pager = null,
        public ?InquiryStatus $status = null,
    ) {
    }
}
