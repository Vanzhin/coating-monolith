<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetReport;

use App\Shared\Application\Query\Query;

final readonly class GetReportQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
