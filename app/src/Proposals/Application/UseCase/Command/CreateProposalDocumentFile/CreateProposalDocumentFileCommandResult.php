<?php
declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateProposalDocumentFile;

use Symfony\Component\HttpFoundation\File\File;

readonly class CreateProposalDocumentFileCommandResult
{
    public function __construct(public ?File $file)
    {
    }
}