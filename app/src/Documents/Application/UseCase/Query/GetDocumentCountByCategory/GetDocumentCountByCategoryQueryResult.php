<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Query\GetDocumentCountByCategory;

use App\Documents\Application\DTO\Document\DocumentCountByCategoryDTO;

class GetDocumentCountByCategoryQueryResult
{
    public function __construct(public DocumentCountByCategoryDTO $result)
    {
    }
}