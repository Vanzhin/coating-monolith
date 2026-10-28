<?php

declare(strict_types=1);

namespace App\Inquiries\Domain\Repository;

/**
 * Порядок сортировки очереди запросов. Значения идут в URL — короткие; label — для UI-дропдауна
 * (как ReportsSort). Дефолт — по дате создания, сначала новые.
 */
enum InquiriesSort: string
{
    case DEFAULT = 'default';         // createdAt DESC — сначала новые
    case CREATED_ASC = 'created_asc'; // сначала старые

    public function label(): string
    {
        return match ($this) {
            self::DEFAULT => 'Сначала новые',
            self::CREATED_ASC => 'Сначала старые',
        };
    }
}
