<?php
declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateProposalDocumentFile;

use App\Proposals\Domain\Aggregate\ProposalDocument\ProposalDocument;
use App\Shared\Application\Command\Command;

readonly class CreateProposalDocumentFileCommand extends Command
{
    public function __construct(public ProposalDocument $document)
    {
    }
}