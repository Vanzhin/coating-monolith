<?php

declare(strict_types=1);

namespace App\Personnel\Domain\Aggregate\Profile\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

readonly class ProfileSpecification implements SpecificationInterface
{
    public function __construct(
        public UniqueUserProfileSpecification $uniqueUser,
    ) {
    }
}
