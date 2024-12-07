<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfo;

use App\Shared\Application\Command\Command;

readonly class RemoveGeneralProposalInfoCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
