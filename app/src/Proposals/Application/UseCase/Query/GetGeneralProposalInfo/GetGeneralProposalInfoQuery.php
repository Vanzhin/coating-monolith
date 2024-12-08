<?php

declare(strict_types=1);

namespace App\Proposals\Application\UseCase\Query\GetGeneralProposalInfo;

use App\Shared\Application\Query\Query;

readonly class GetGeneralProposalInfoQuery extends Query
{
    public function __construct(public string $proposalId)
    {
    }
}
