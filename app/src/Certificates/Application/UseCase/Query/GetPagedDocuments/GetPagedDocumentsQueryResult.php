<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetPagedDocuments;

use App\Certificates\Application\DTO\Documents\DocumentDTO;
use App\Shared\Domain\Repository\Pager;

final readonly class GetPagedDocumentsQueryResult
{
    /**
     * @param list<DocumentDTO> $documents
     */
    public function __construct(
        public array $documents,
        public Pager $pager,
    ) {
    }
}
