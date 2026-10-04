<?php

declare(strict_types=1);

namespace App\Compliance\Application\DTO\Dashboard;

/** Разрез по отделу: счётчики бакетов — по (человек × требование); `peopleCount` — отдельно число людей отдела. */
final class DeptBreakdownDTO
{
    public string $title;
    public BucketCountsDTO $counts;
    public int $peopleCount = 0;
}
