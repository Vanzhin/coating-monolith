<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Query\GetDocument;

use App\Shared\Application\Query\Query;

final readonly class GetDocumentQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
