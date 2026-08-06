<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

/**
 * Порядок сортировки результата поиска систем покрытий.
 * DEFAULT: FTS-ранк когда есть q, title ASC когда q пустое.
 */
enum CoatingSystemSort: string
{
    case DEFAULT = 'default';
    case TITLE_ASC = 'title_asc';
    case TITLE_DESC = 'title_desc';
    case MIN_APPLICATION_TIME_ASC = 'min_application_time_asc';
    case MIN_APPLICATION_TIME_DESC = 'min_application_time_desc';

    public function title(): string
    {
        return match ($this) {
            self::DEFAULT => 'По релевантности',
            self::TITLE_ASC => 'Название А‑Я',
            self::TITLE_DESC => 'Название Я‑А',
            self::MIN_APPLICATION_TIME_ASC => 'Быстрее нанести',
            self::MIN_APPLICATION_TIME_DESC => 'Дольше нанести',
        };
    }
}
