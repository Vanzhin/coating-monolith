<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Command\AddDocument;

class AddDocumentCommandResult
{
    public function __construct(public string $id)
    {
    }
}
