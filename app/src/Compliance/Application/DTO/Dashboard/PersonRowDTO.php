<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

/** Строка человека на дашборде: идентичность + худший бакет + сводка по типам + группы требований (деталь). */
final class PersonRowDTO
{
    public string $profileId;
    public string $fullName;
    public string $initials;
    public string $positionTitle;
    public string $departmentTitle;
    public string $worstBucket;
    public BucketCountsDTO $material;
    public BucketCountsDTO $nonMaterial;
    /** @var list<RequirementGroupDTO> */
    public array $groups = [];
}
