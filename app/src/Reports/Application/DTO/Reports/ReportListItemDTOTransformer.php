<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Reports;

use App\Reports\Domain\Aggregate\Report\Report;

class ReportListItemDTOTransformer
{
    public function fromEntity(Report $report, ?string $ownerLabel = null): ReportListItemDTO
    {
        $type = $report->getType();

        $dto = new ReportListItemDTO();
        $dto->id = $report->getId();
        $dto->typeKey = $type?->value;
        $dto->typeLabel = $type?->label();
        $dto->status = $report->getStatus()->value;
        $dto->statusLabel = $report->getStatus()->label();
        $dto->editable = $report->isEditable();
        $dto->actNumber = $report->getActNumber();
        $dto->reportDate = $report->getReportDate()?->format('d.m.Y');
        $dto->projectTitle = $report->getProject()?->title;
        $dto->customerTitle = $report->getCustomer()?->title;
        $dto->contractorTitle = $report->getContractor()?->title;
        $dto->systemTitle = $report->getSystem()?->title;
        $dto->ownerLabel = $ownerLabel;
        $dto->layerCount = $this->layerCount($report->getContent());
        $dto->updatedAt = $report->getUpdatedAt()->format('d.m.Y');

        return $dto;
    }

    /**
     * @param iterable<Report>      $reports
     * @param array<string, string> $ownerLabels ownerId → email (только админу; иначе пусто)
     *
     * @return list<ReportListItemDTO>
     */
    public function fromEntityList(iterable $reports, array $ownerLabels = []): array
    {
        $items = [];
        foreach ($reports as $report) {
            $items[] = $this->fromEntity($report, $ownerLabels[$report->getOwnerId()] ?? null);
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $content
     */
    private function layerCount(array $content): int
    {
        foreach (['system', 'application'] as $block) {
            $layers = $content[$block]['layers'] ?? null;
            if (is_array($layers) && [] !== $layers) {
                return count($layers);
            }
        }

        return 0;
    }
}
