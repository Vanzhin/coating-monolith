<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfo;

use App\Proposals\Application\Service\AccessControl\GeneralProposalInfoAccessControl;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\AssertService;

readonly class RemoveGeneralProposalInfoCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoRepositoryInterface $generalProposalInfoRepository,
        private GeneralProposalInfoAccessControl       $generalProposalInfoAccessControl
    )
    {
    }

    public function __invoke(RemoveGeneralProposalInfoCommand $command): RemoveGeneralProposalInfoCommandResult
    {
        $proposalInfo = $this->generalProposalInfoRepository->findOneById($command->id);
        AssertService::notNull($proposalInfo, 'Форма не найдена.');
        AssertService::true(
            $this->generalProposalInfoAccessControl->canDeleteGeneralProposalInfo(
                $proposalInfo->getOwnerId(),
                $command->id
            ),
            'Запрещено.'
        );
        $this->generalProposalInfoRepository->remove($proposalInfo);

        return new RemoveGeneralProposalInfoCommandResult();
    }
}
