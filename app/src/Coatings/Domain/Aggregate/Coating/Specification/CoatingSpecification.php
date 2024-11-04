<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Coating\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

class CoatingSpecification implements SpecificationInterface
{
    public function __construct(
        public readonly UniqueTitleCoatingSpecification $uniqueTitleCoatingSpecification
    )
    {
    }

}