<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

/**
 * Порядок сортировки списка актов выдачи (значения кодируют поле + направление, как {@see \App\Coatings\Domain\Repository\CoatingSort}).
 * Идут в URL — короткие. Сортируем в SQL репозитория по колонкам документа (дата/№/статус); ФИО и имя требования —
 * кросс-контекст (Personnel/Requirement), по ним сортировки нет.
 */
enum ComplianceActsSort: string
{
    case DEFAULT = 'default';            // дата выдачи, сначала свежие
    case DATE_ASC = 'date_asc';          // дата выдачи, сначала старые
    case NUMBER_ASC = 'number_asc';
    case NUMBER_DESC = 'number_desc';
    case STATUS_ASC = 'status_asc';
    case STATUS_DESC = 'status_desc';

    public function label(): string
    {
        return match ($this) {
            self::DEFAULT => 'Сначала новые',
            self::DATE_ASC => 'Сначала старые',
            self::NUMBER_ASC => '№ акта ↑',
            self::NUMBER_DESC => '№ акта ↓',
            self::STATUS_ASC => 'Статус ↑',
            self::STATUS_DESC => 'Статус ↓',
        };
    }
}
