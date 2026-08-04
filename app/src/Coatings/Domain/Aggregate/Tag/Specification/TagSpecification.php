<?php

declare(strict_types=1);

namespace App\Coatings\Domain\Aggregate\Tag\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

class TagSpecification implements SpecificationInterface
{
    public function __construct(
        public readonly UniqueTitleAndTypeTagSpecification $titleAndTypeTagSpecification
    ) {
    }
}
