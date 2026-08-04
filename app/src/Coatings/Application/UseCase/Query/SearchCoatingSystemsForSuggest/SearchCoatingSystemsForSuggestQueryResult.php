<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystemsForSuggest;

final readonly class SearchCoatingSystemsForSuggestQueryResult
{
    /** @param list<array{id: string, title: string}> $items */
    public function __construct(public array $items)
    {
    }
}
