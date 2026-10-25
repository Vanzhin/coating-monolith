<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SuggestCoatingSystems;

use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Shared\Application\Query\Query;

/**
 * Лёгкий typeahead-поиск систем покрытий. Тот же фильтр/findByFilter, что у полного
 * SearchCoatingSystemsQueryHandler (листинг), но без тяжёлого CoatingSystemDTO: нужны
 * только {id, title} для выпадающего списка подсказок.
 */
final readonly class SuggestCoatingSystemsQuery extends Query
{
    public function __construct(public CoatingSystemsFilter $filter)
    {
    }
}
