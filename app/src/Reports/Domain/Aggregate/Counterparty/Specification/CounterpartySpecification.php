<?php

declare(strict_types=1);

namespace App\Reports\Domain\Aggregate\Counterparty\Specification;

use App\Shared\Domain\Specification\SpecificationInterface;

readonly class CounterpartySpecification implements SpecificationInterface
{
    public function __construct(
        public UniqueTitleCounterpartySpecification $uniqueTitle,
        public UniqueTinCounterpartySpecification $uniqueTin,
    ) {
    }
}
