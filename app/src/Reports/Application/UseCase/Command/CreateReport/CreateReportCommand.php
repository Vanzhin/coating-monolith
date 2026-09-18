<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Command\CreateReport;

use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Application\Command\Command;

final readonly class CreateReportCommand extends Command
{
    public function __construct(
        public ?ReportType $type,
        public ?\DateTimeImmutable $reportDate = null,
        public ?string $actNumber = null,
        public ?string $projectId = null,
        public ?string $customerId = null,
        public ?string $contractorId = null,
        public ?string $systemId = null,
    ) {
    }
}
