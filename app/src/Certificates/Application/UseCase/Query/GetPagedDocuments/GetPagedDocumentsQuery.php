<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetPagedDocuments;

use App\Certificates\Domain\Repository\DocumentsFilter;
use App\Shared\Application\Query\Query;

final readonly class GetPagedDocumentsQuery extends Query
{
    public function __construct(public DocumentsFilter $filter)
    {
    }
}
