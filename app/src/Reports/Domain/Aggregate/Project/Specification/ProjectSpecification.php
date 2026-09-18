<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Project\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

readonly class ProjectSpecification implements SpecificationInterface
{
    public function __construct(public UniqueTitleProjectSpecification $uniqueTitle)
    {
    }
}
