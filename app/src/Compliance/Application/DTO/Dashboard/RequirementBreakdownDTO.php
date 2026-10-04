<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

/** Разрез по требованию: охваченные люди, посчитанные по худшему бакету в этом требовании. */
final class RequirementBreakdownDTO
{
    public string $requirementId;
    public string $name;
    /** material|non_material */
    public string $type;
    public string $typeLabel;
    public BucketCountsDTO $counts;
    public int $peopleCount = 0;
}
