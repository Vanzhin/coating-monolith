<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfoItem;

use App\Proposals\Application\Service\AccessControl\GeneralProposalInfoAccessControl;
use App\Proposals\Domain\Repository\GeneralProposalInfoItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\AssertService;

readonly class RemoveGeneralProposalInfoItemCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoItemRepositoryInterface $generalProposalInfoItemRepository,
        private GeneralProposalInfoAccessControl           $generalProposalInfoAccessControl
    )
    {
    }

    public function __invoke(RemoveGeneralProposalInfoItemCommand $command): RemoveGeneralProposalInfoItemCommandResult
    {
        $proposalInfoItem = $this->generalProposalInfoItemRepository->findOneById($command->id);
        AssertService::notNull($proposalInfoItem, 'Элемент форма не найден.');
        AssertService::true(
            $this->generalProposalInfoAccessControl->canDeleteGeneralProposalInfo(
                $proposalInfoItem->getProposal()->getOwnerId(),
                $command->id
            ),
            'Запрещено.'
        );
        $this->generalProposalInfoItemRepository->remove($proposalInfoItem);

        return new RemoveGeneralProposalInfoItemCommandResult();
    }
}
