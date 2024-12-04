<?php

declare(strict_types=1);

namespace App\Proposals\Application\UseCase\Query\GetPagedGeneralProposalInfo;

use App\Proposals\Domain\Repository\GeneralProposalInfoFilter;
use App\Shared\Application\Query\Query;

readonly class GetPagedGeneralProposalInfoQuery extends Query
{
    public function __construct(public GeneralProposalInfoFilter $filter)
    {
    }
}
