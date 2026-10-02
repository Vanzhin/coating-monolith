<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

/** Разрез по отделу: люди отдела, посчитанные по худшему бакету. */
final class DeptBreakdownDTO
{
    public string $title;
    public BucketCountsDTO $counts;
}
