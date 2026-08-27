<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\CreateDocument;

final readonly class CreateDocumentCommandResult
{
    public function __construct(public string $id)
    {
    }
}
