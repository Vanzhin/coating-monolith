<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\UpdateGeneralProposalInfo;

use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTO;
use App\Shared\Application\Command\Command;

readonly class UpdateGeneralProposalInfoCommand extends Command
{
    public function __construct(
        public string                 $proposalInfoId,
        public GeneralProposalInfoDTO $generalProposalInfoDTO,
    )
    {
    }
}
