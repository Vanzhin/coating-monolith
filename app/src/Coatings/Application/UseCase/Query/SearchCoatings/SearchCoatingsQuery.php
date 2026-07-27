<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatings;

use App\Shared\Application\Query\Query;

/**
 * Лёгкий поиск покрытий для typeahead-подсказок.
 * Возвращает только минимальный набор полей: id, title, base, dftMin, dftMax.
 */
readonly class SearchCoatingsQuery extends Query
{
    public function __construct(
        public ?string $q = null,
        public int $limit = 30,
    ) {
    }
}
