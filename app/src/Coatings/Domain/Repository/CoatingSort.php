<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

/**
 * Порядок сортировки для списка покрытий. Значения enum'а идут в URL —
 * поэтому короткие лейблы. Метка `label` — для UI-dropdown'a.
 */
enum CoatingSort: string
{
    /** По умолчанию: FTS-rank при поиске, алфавит по названию иначе. */
    case DEFAULT = 'default';
    case TITLE_ASC = 'title_asc';
    case TITLE_DESC = 'title_desc';
    case MANUFACTURER_ASC = 'manufacturer_asc';

    public function label(): string
    {
        return match ($this) {
            self::DEFAULT           => 'По умолчанию',
            self::TITLE_ASC         => 'Название А→Я',
            self::TITLE_DESC        => 'Название Я→А',
            self::MANUFACTURER_ASC  => 'По производителю',
        };
    }
}
