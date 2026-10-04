<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Position\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

readonly class PositionSpecification implements SpecificationInterface
{
    public function __construct(
        public UniqueTitlePositionSpecification $uniqueTitle,
    ) {
    }
}
