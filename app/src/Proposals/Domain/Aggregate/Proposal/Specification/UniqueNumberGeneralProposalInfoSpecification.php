<?php
declare(strict_types=1);


namespace App\Proposals\Domain\Aggregate\Proposal\Specification;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;
use App\Shared\Domain\Service\AssertService;
use App\Shared\Domain\Specification\SpecificationInterface;

readonly class UniqueNumberGeneralProposalInfoSpecification implements SpecificationInterface
{
    public function __construct(private GeneralProposalInfoRepositoryInterface $generalProposalInfoRepository)
    {
    }

    public function satisfy(GeneralProposalInfo $proposalInfo): void
    {
        $exist = $this->generalProposalInfoRepository->findOneByNumberAndUserId(
            $proposalInfo->getNumber(), $proposalInfo->getOwnerId());
        if ($exist?->getId() !== $proposalInfo->getId()) {
            AssertService::null(
                $exist,
                sprintf('Форма с номером "%s" уже существует.', $proposalInfo->getNumber())
            );
        }
    }

}