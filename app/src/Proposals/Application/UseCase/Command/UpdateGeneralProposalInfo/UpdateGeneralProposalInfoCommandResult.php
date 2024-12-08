<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\UpdateGeneralProposalInfo;

use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTO;

class UpdateGeneralProposalInfoCommandResult
{
    public function __construct(public ?GeneralProposalInfoDTO $dto = null)
    {
    }
}
