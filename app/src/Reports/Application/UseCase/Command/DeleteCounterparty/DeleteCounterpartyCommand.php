<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\DeleteCounterparty;

use App\Shared\Application\Command\Command;

final readonly class DeleteCounterpartyCommand extends Command
{
    public function __construct(public string $id)
    {
    }
}
