<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\RejectReport;

use App\Shared\Application\Command\Command;

final readonly class RejectReportCommand extends Command
{
    public function __construct(
        public string $reportId,
        public ?string $reason = null,
    ) {
    }
}
