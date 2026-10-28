<?php

declare(strict_types=1);

namespace App\Coatings\Application\DTO\CoatingSystems;

/**
 * Один вариант выбора для селекта фильтра систем: хранимое значение + подпись. В отличие от
 * FilterFacetLabel (подпись уже выбранного значения), это вариант для выпадашки формы.
 */
final readonly class FilterOption
{
    public function __construct(public string $value, public string $label)
    {
    }
}
