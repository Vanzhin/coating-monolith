<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\DeleteDocument;

use App\Shared\Application\Command\Command;

final readonly class DeleteDocumentCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
