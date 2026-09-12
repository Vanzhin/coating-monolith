<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatings;

use App\Coatings\Application\DTO\Coatings\CoatingSuggestDTO;
use App\Shared\Domain\Repository\Pager;

/**
 * Результат лёгкого поиска покрытий (typeahead) — как GetPagedCoatingsQueryResult, только
 * лёгкие CoatingSuggestDTO. Пейджер — для подгрузки следующих страниц в выпадающем списке.
 */
readonly class SearchCoatingsQueryResult
{
    /**
     * @param CoatingSuggestDTO[] $coatings
     */
    public function __construct(public array $coatings, public Pager $pager)
    {
    }
}
