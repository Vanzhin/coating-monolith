<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateProposalDocumentTemplate;

use App\Proposals\Domain\Service\GeneralProposalInfoMaker;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class CreateProposalDocumentTemplateCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoMaker $generalProposalInfoMaker,
    )
    {
    }

    public function __invoke(CreateProposalDocumentTemplateCommand $command): CreateProposalDocumentTemplateCommandResult
    {
       //todo надо ли это?

        return new CreateProposalDocumentTemplateCommandResult(
            $generalProposalInfo->getId()
        );
    }
}
