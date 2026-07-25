<?php

declare(strict_types=1);

namespace App\Proposals\Application\UseCase\Command\CreateProposalDocumentTemplate;

use App\Shared\Application\Command\CommandHandlerInterface;

readonly class CreateProposalDocumentTemplateCommandHandler implements CommandHandlerInterface
{
    // todo реализация — сейчас handler-заглушка (черновик)
    public function __invoke(CreateProposalDocumentTemplateCommand $command): CreateProposalDocumentTemplateCommandResult
    {
        throw new \LogicException('CreateProposalDocumentTemplateCommandHandler not implemented');
    }
}
