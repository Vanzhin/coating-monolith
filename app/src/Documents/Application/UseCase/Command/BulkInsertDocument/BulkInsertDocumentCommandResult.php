<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Command\BulkInsertDocument;

class BulkInsertDocumentCommandResult
{
    public function __construct(public bool $insert)
    {
    }
}
