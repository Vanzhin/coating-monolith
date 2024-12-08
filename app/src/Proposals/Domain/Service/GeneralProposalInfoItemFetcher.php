<?php

declare(strict_types=1);

namespace App\Proposals\Domain\Service;

use App\Proposals\Domain\Aggregate\Proposal\GeneralProposalInfoItem;
use App\Proposals\Domain\Repository\GeneralProposalInfoItemRepositoryInterface;

readonly class GeneralProposalInfoItemFetcher
{
    public function __construct(private GeneralProposalInfoItemRepositoryInterface $generalProposalInfoItemRepository)
    {
    }

    public function getRequiredGeneralProposalInfo(string $id): ?GeneralProposalInfoItem
    {
        return $this->generalProposalInfoItemRepository->findOneById($id);
    }
}
