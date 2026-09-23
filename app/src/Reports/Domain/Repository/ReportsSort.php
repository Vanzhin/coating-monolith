<?php

declare(strict_types=1);

namespace App\Reports\Domain\Repository;

/**
 * Порядок сортировки списка отчётов. Значения enum'а идут в URL — короткие. Метка `label` — для
 * UI-dropdown'а (как у CoatingSort). Дефолт — по дате создания, сначала новые.
 */
enum ReportsSort: string
{
    case DEFAULT = 'default';           // createdAt DESC — сначала новые
    case CREATED_ASC = 'created_asc';   // сначала старые
    case UPDATED_DESC = 'updated_desc'; // недавно изменённые

    public function label(): string
    {
        return match ($this) {
            self::DEFAULT => 'Сначала новые',
            self::CREATED_ASC => 'Сначала старые',
            self::UPDATED_DESC => 'Недавно изменённые',
        };
    }
}
