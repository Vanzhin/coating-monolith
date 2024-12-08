<?php

declare(strict_types=1);


namespace App\Proposals\Application\UseCase\Command\RemoveGeneralProposalInfoItem;

use App\Shared\Application\Command\Command;

readonly class RemoveGeneralProposalInfoItemCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
