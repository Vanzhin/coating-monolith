<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Query\GetPagedDocuments;

use App\Shared\Domain\Repository\Pager;

class GetPagedDocumentsQueryResult
{
    public function __construct(public array $documents, public Pager $pager)
    {
    }
}