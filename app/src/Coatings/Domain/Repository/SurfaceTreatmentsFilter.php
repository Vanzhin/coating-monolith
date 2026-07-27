<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Repository;

use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;

final readonly class SurfaceTreatmentsFilter
{
    public function __construct(
        public ?Substrate $substrate = null,
        public ?string $q = null,
    ) {
    }
}
