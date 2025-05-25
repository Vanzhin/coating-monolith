<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Query\GetPagedDocuments;

use App\Documents\Domain\Repository\DocumentFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedDocumentsQuery extends Query
{
    public function __construct(public DocumentFilter $filter)
    {
    }
}