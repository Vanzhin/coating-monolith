<?php

declare(strict_types=1);

namespace App\Shared\Application\Comparison;

final readonly class ComparisonRow
{
    /** @param list<mixed> $values значения по объектам, в порядке входа */
    public function __construct(
        public string $field,
        public array $values,
        public bool $isDifferent,
    ) {
    }
}
