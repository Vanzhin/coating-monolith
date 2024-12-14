<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateProposalDocumentTemplate;

class CreateProposalDocumentTemplateCommandResult
{
    public function __construct(
        public string $id,
    )
    {
    }
}
