<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\ApproveReport;

use App\Shared\Application\Command\Command;

final readonly class ApproveReportCommand extends Command
{
    public function __construct(public string $reportId)
    {
    }
}
