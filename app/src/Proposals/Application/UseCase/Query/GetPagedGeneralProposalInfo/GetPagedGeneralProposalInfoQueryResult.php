<?php

declare(strict_types=1);

namespace App\Proposals\Application\UseCase\Query\GetPagedGeneralProposalInfo;

use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTO;
use App\Shared\Domain\Repository\Pager;

readonly class GetPagedGeneralProposalInfoQueryResult
{
    /**
     * @param GeneralProposalInfoDTO[] $proposals
     * @param Pager $pager
     */
    public function __construct(public array $proposals, public Pager $pager)
    {
    }
}
