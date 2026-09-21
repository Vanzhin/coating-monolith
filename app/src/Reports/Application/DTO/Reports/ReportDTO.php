<?php

declare(strict_types=1);

namespace App\Reports\Application\DTO\Reports;

class ReportDTO
{
    public string $id;
    public string $ownerId;
    public ?string $reviewerId = null;
    public ?string $typeKey = null;
    public ?string $typeLabel = null;
    public string $status;
    public string $statusLabel;
    public ?string $rejectionReason = null;
    public ?string $reportDate = null;
    public ?string $actNumber = null;
    public ?string $address = null;
    public ?string $workFrom = null;
    public ?string $workTo = null;
    public ?string $projectId = null;
    public ?string $projectTitle = null;
    public ?string $customerId = null;
    public ?string $customerTitle = null;
    public ?string $contractorId = null;
    public ?string $contractorTitle = null;
    public ?string $systemId = null;
    public ?string $systemTitle = null;
    /** @var array<string, mixed> */
    public array $content = [];
    public string $createdAt;
    public string $updatedAt;
}
