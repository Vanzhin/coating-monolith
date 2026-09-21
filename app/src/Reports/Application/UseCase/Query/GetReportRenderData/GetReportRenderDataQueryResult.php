<?php

declare(strict_types=1);

namespace App\Reports\Application\UseCase\Query\GetReportRenderData;

use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Domain\Templating\RenderData;

final readonly class GetReportRenderDataQueryResult
{
    public function __construct(
        public RenderData $data,
        public ReportType $type,
        public int $layerCount,
        public ?string $actNumber,
    ) {
    }
}
