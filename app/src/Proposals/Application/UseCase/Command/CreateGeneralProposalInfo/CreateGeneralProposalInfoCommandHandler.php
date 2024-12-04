<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateGeneralProposalInfo;

use App\Proposals\Domain\Service\GeneralProposalInfoMaker;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class CreateGeneralProposalInfoCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoMaker $generalProposalInfoMaker,
    )
    {
    }

    public function __invoke(CreateGeneralProposalInfoCommand $command): CreateGeneralProposalInfoCommandResult
    {
        $generalProposalInfo = $this->generalProposalInfoMaker->make(
            $command->dto->number,
            $command->dto->ownerId,
            $command->dto->unit,
            $command->dto->projectTitle,
            $command->dto->projectArea,
            $command->dto->description,
            $command->dto->basis,
            $command->dto->projectStructureDescription,
            $command->dto->durability,
            $command->dto->category,
            $command->dto->treatment,
            $command->dto->method,
            $command->dto->loss,
            $command->dto->coats,
        );

        return new CreateGeneralProposalInfoCommandResult(
            $generalProposalInfo->getId()
        );
    }
}
