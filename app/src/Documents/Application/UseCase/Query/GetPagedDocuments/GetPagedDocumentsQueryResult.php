<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Query\GetPagedDocuments;

use App\Shared\Domain\Repository\Pager;

class GetPagedDocumentsQueryResult
{
    /**
     * @param list<\App\Documents\Application\DTO\Document\DocumentDTO> $documents
     */
    public function __construct(public array $documents, public Pager $pager)
    {
    }
}
