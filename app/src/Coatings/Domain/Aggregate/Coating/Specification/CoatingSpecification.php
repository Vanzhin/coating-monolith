<?php
declare(strict_types=1);


namespace App\Coatings\Domain\Aggregate\Coating\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

readonly class CoatingSpecification implements SpecificationInterface
{
    public function __construct(
        public UniqueTitleCoatingSpecification $uniqueTitleCoatingSpecification
    )
    {
    }

}