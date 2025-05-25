<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Command\BulkInsertDocument;

use App\Shared\Application\Command\Command;

readonly class BulkInsertDocumentCommand extends Command
{
    public function __construct(public string $filePath, public ?string $db)
    {
    }
}
