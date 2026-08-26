<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Repository;

/**
 * Порядок сортировки списка документов.
 * DEFAULT: FTS-ранк когда есть q, иначе по дате выдачи (свежие сначала).
 */
enum DocumentSort: string
{
    case DEFAULT = 'default';
    case DATE_DESC = 'date_desc';
    case ISSUER_ASC = 'issuer_asc';
    case TITLE_ASC = 'title_asc';

    public function title(): string
    {
        return match ($this) {
            self::DEFAULT => 'По релевантности',
            self::DATE_DESC => 'Сначала свежие',
            self::ISSUER_ASC => 'По организации',
            self::TITLE_ASC => 'По названию',
        };
    }
}
