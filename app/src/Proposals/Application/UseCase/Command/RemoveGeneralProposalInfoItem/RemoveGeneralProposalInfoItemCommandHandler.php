<?php

declare(strict_types=1);

namespace App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfoItem;

use App\Proposals\Application\Service\AccessControl\GeneralProposalInfoAccessControl;
use App\Proposals\Domain\Repository\GeneralProposalInfoItemRepositoryInterface;
use App\Shared\Application\Command\CommandHandlerInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Infrastructure\Exception\ForbiddenException;

readonly class RemoveGeneralProposalInfoItemCommandHandler implements CommandHandlerInterface
{
    public function __construct(
        private GeneralProposalInfoItemRepositoryInterface $generalProposalInfoItemRepository,
        private GeneralProposalInfoAccessControl $generalProposalInfoAccessControl
    ) {
    }

    public function __invoke(RemoveGeneralProposalInfoItemCommand $command): RemoveGeneralProposalInfoItemCommandResult
    {
        $proposalInfoItem = $this->generalProposalInfoItemRepository->findOneById($command->id);
        AssertService::notNull($proposalInfoItem, 'Элемент форма не найден.');
        if (!$this->generalProposalInfoAccessControl->canEdit($proposalInfoItem->getProposal())) {
            throw new ForbiddenException();
        }
        $this->generalProposalInfoItemRepository->remove($proposalInfoItem);

        return new RemoveGeneralProposalInfoItemCommandResult();
    }
}
