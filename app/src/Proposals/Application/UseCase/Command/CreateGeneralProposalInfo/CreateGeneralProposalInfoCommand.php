<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateGeneralProposalInfo;

use App\Proposals\Application\DTO\GeneralProposalInfo\GeneralProposalInfoDTO;
use App\Shared\Application\Command\Command;

readonly class CreateGeneralProposalInfoCommand extends Command
{
    public function __construct(public GeneralProposalInfoDTO $dto)
    {
    }
}
