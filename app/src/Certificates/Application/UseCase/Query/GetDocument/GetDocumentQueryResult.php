<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetDocument;

use App\Certificates\Application\DTO\Documents\DocumentDTO;

final readonly class GetDocumentQueryResult
{
    public function __construct(public ?DocumentDTO $document)
    {
    }
}
