<?php

declare(strict_types=1);

namespace App\Certificates\Domain\Aggregate\Issuer\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

class IssuerSpecification implements SpecificationInterface
{
    public function __construct(
        public readonly UniqueTitleIssuerSpecification $uniqueTitle,
    ) {
    }
}
