<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Reports;

use App\Reports\Domain\Aggregate\Report\Report;

class ReportDTOTransformer
{
    public function fromEntity(Report $report): ReportDTO
    {
        $type = $report->getType();

        $dto = new ReportDTO();
        $dto->id = $report->getId();
        $dto->ownerId = $report->getOwnerId();
        $dto->reviewerId = $report->getReviewerId();
        $dto->typeKey = $type?->value;
        $dto->typeLabel = $type?->label();
        $dto->status = $report->getStatus()->value;
        $dto->statusLabel = $report->getStatus()->label();
        $dto->rejectionReason = $report->getRejectionReason();
        $dto->reportDate = $report->getReportDate()?->format('Y-m-d');
        $dto->actNumber = $report->getActNumber();
        $dto->address = $report->getAddress();
        $workPeriod = $report->getWorkPeriod();
        $dto->workFrom = $workPeriod?->getFrom()?->format('Y-m-d');
        $dto->workTo = $workPeriod?->getTo()?->format('Y-m-d');

        $project = $report->getProject();
        $dto->projectId = $project?->id;
        $dto->projectTitle = $project?->title;
        $customer = $report->getCustomer();
        $dto->customerId = $customer?->id;
        $dto->customerTitle = $customer?->title;
        $contractor = $report->getContractor();
        $dto->contractorId = $contractor?->id;
        $dto->contractorTitle = $contractor?->title;
        $system = $report->getSystem();
        $dto->systemId = $system?->id;
        $dto->systemTitle = $system?->title;

        $dto->content = $report->getContent();
        $dto->createdAt = $report->getCreatedAt()->format('Y-m-d H:i:s');
        $dto->updatedAt = $report->getUpdatedAt()->format('Y-m-d H:i:s');

        return $dto;
    }
}
