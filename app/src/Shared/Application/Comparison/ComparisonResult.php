<?php

declare(strict_types=1);

namespace App\Shared\Application\Comparison;

final readonly class ComparisonResult
{
    /** @param list<ComparisonRow> $rows */
    public function __construct(public array $rows)
    {
    }
}
