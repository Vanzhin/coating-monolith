<?php

declare(strict_types=1);

namespace App\Reports\Domain\Repository;

use App\Reports\Domain\Aggregate\Report\Report;

interface ReportRepositoryInterface
{
    public function add(Report $report): void;

    public function remove(Report $report): void;

    public function findOneById(string $id): ?Report;
}
