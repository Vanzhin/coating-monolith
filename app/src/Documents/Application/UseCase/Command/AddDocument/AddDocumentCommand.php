<?php

declare(strict_types=1);

namespace App\Documents\Application\UseCase\Command\AddDocument;

use App\Documents\Application\DTO\Document\DocumentDTO;
use App\Shared\Application\Command\Command;

readonly class AddDocumentCommand extends Command
{
    public function __construct(public DocumentDTO $dto)
    {
    }
}
