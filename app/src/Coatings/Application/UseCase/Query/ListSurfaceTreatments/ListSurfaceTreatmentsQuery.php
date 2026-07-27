<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\ListSurfaceTreatments;

use App\Coatings\Domain\Repository\SurfaceTreatmentsFilter;
use App\Shared\Application\Query\Query;

readonly class ListSurfaceTreatmentsQuery extends Query
{
    public function __construct(
        public SurfaceTreatmentsFilter $filter,
        public int $page = 1,
        public int $perPage = 20,
    ) {
    }
}
