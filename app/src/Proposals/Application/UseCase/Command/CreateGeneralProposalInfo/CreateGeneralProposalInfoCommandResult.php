<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\CreateGeneralProposalInfo;

class CreateGeneralProposalInfoCommandResult
{
    public function __construct(
        public string $id,
    )
    {
    }
}
