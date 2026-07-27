<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\FindSurfaceTreatmentById;

use App\Shared\Application\Query\Query;

readonly class FindSurfaceTreatmentByIdQuery extends Query
{
    public function __construct(public string $id)
    {
    }
}
