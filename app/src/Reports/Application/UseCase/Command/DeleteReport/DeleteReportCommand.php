<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\DeleteReport;

use App\Shared\Application\Command\Command;

final readonly class DeleteReportCommand extends Command
{
    public function __construct(public string $reportId)
    {
    }
}
