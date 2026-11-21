<?php

declare(strict_types=1);

namespace App\Waybills\Application\UseCase\Command\LockWaybill;

use App\Shared\Application\Command\Command;

final readonly class LockWaybillCommand extends Command
{
    public function __construct(public string $waybillId, public ?int $expectedVersion = null)
    {
    }
}
