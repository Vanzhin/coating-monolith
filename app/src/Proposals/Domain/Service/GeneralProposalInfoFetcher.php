<?php

declare(strict_types=1);

namespace App\Proposals\Domain\Service;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfo;
use App\Proposals\Domain\Repository\GeneralProposalInfoRepositoryInterface;

readonly class GeneralProposalInfoFetcher
{
    public function __construct(private GeneralProposalInfoRepositoryInterface $generalProposalInfoRepository)
    {
    }

    public function getRequiredGeneralProposalInfo(string $id): ?GeneralProposalInfo
    {
        return $this->generalProposalInfoRepository->findOneById($id);
    }
}
