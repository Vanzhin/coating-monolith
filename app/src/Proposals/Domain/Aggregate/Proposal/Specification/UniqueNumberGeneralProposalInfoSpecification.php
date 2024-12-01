<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\Proposal\Specification;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Specification\SpecificationInterface;

class UniqueNumberGeneralProposalInfoSpecification implements SpecificationInterface
{
    public function __construct(private readonly GeneralProposalInfoRepositoryInterface $generalProposalInfoRepository)
    {
    }

    public function satisfy(GeneralProposalInfo $proposalInfo): void
    {
        $exist = $this->generalProposalInfoRepository->findOneByNumber($proposalInfo->getNumber());
        if ($exist?->getId() !== $proposalInfo->getId()) {
            AssertService::null(
                $exist,
                sprintf('GeneralProposal with number "%s" already exist.', $proposalInfo->getNumber())
            );
        }
    }

}