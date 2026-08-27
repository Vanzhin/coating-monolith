<?php

declare(strict_types=1);

namespace App\Certificates\Application\UseCase\Command\UpdateDocument;

final readonly class UpdateDocumentCommandResult
{
    public function __construct(public string $id)
    {
    }
}
