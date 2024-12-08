<?php

declare(strict_types=1);

namespace App\Proposals\Application\UseCase\Query\GetGeneralProposalInfo;


use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTO;

readonly class GetGeneralProposalInfoQueryResult
{
    public function __construct(public ?GeneralProposalInfoDTO $generalProposalInfoDTO)
    {
    }
}
