<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Query\GetDocumentCountByCategory;

use App\Documents\Domain\Repository\DocumentFilter;
use App\Shared\Application\Query\Query;

readonly class GetDocumentCountByCategoryQuery extends Query
{
    public function __construct(public DocumentFilter $filter)
    {
    }
}