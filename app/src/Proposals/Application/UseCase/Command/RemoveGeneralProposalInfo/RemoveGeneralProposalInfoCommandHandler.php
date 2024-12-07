<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfo;

use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;

readonly class RemoveGeneralProposalInfoCommandHandler implements CommandHandlerInterface
{
    public function __construct(private GeneralProposalInfoRepositoryInterface $generalProposalInfoRepository)
    {
    }

    public function __invoke(RemoveGeneralProposalInfoCommand $command): RemoveGeneralProposalInfoCommandResult
    {
        $manufacturer = $this->generalProposalInfoRepository->findOneById($command->id);
        $this->generalProposalInfoRepository->remove($manufacturer);

        return new RemoveGeneralProposalInfoCommandResult();
    }
}
