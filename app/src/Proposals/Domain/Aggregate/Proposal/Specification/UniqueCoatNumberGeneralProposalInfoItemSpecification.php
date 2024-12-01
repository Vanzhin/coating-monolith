<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\Proposal\Specification;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoItem;
use App\Proposals\Domain\Repository\GeneralProposalInfoItemRepositoryInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Specification\SpecificationInterface;

class UniqueCoatNumberGeneralProposalInfoItemSpecification implements SpecificationInterface
{
    public function __construct(
        private readonly GeneralProposalInfoItemRepositoryInterface $generalProposalInfoItemRepository
    )
    {
    }

    public function satisfy(GeneralProposalInfoItem $item): void
    {
        $exist = $this->generalProposalInfoItemRepository->findOneByProposalIdAndCoatNumber(
            $item->getProposal()->getId(),
            $item->getCoatNumber()
        );
        AssertService::null(
            $exist,
            sprintf('Coat number "%s" already exist in this proposal.', $item->getCoatNumber())
        );
    }
}