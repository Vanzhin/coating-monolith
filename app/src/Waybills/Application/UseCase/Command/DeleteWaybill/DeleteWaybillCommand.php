<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\DeleteWaybill;

use App\Shared\Application\Command\Command;

final readonly class DeleteWaybillCommand extends Command
{
    public function __construct(public string $waybillId, public int $expectedVersion)
    {
    }
}
