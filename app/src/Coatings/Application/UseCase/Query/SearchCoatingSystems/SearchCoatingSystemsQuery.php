<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystems;

use App\Coatings\Domain\Repository\CoatingSystemsFilter;
use App\Coatings\Domain\Repository\SearchQuery;
use App\Shared\Application\Query\Query;

final readonly class SearchCoatingSystemsQuery extends Query
{
    public function __construct(public CoatingSystemsFilter $filter)
    {
    }

    /**
     * Поиск систем по строке (typeahead). Доменный фильтр/SearchQuery строятся ВНУТРИ контекста Coatings —
     * потребитель (в т.ч. другой контекст) передаёт только строку и не тянет домен Coatings.
     * Null — если строка пустая/слишком короткая (нет запроса); слишком длинная кинет AppException.
     */
    public static function bySearch(?string $raw): ?self
    {
        $search = SearchQuery::tryFromString($raw);

        return null === $search ? null : new self(new CoatingSystemsFilter(search: $search));
    }
}
