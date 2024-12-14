<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateProposalDocumentTemplate;

use App\Shared\Application\Command\Command;
use Symfony\Component\HttpFoundation\File\UploadedFile;

readonly class CreateProposalDocumentTemplateCommand extends Command
{
    public function __construct(public string $description, public array $availableTemplates, public UploadedFile $uploadedFile)
    {
    }
}
