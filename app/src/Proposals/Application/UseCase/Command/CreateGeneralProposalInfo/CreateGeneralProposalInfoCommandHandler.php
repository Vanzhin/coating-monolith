<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateGeneralProposalInfo;

use App\Coatings\Domain\Service\CoatingMaker;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class CreateGeneralProposalInfoCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private CoatingMaker $coatingMaker,
    )
    {
    }

    public function __invoke(CreateGeneralProposalInfoCommand $command): CreateGeneralProposalInfoCommandResult
    {
        //todo

        return new CreateGeneralProposalInfoCommandResult(
            $coating->getId()
        );
    }
}
