<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Reports;

/** Лёгкое представление отчёта для карточки списка (без content целиком). */
class ReportListItemDTO
{
    public string $id;
    public ?string $typeKey = null;
    public ?string $typeLabel = null;
    public string $status;
    public string $statusLabel;
    public ?string $actNumber = null;
    public ?string $reportDate = null;
    public ?string $projectTitle = null;
    public ?string $customerTitle = null;
    public ?string $contractorTitle = null;
    public ?string $systemTitle = null;
    /** email владельца — заполняется только админу (трансформер получает карту). */
    public ?string $ownerLabel = null;
    public int $layerCount = 0;
    public string $updatedAt;
}
