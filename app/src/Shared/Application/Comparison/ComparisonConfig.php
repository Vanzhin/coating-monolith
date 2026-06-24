<?php

declare(strict_types=1);

namespace App\Shared\Application\Comparison;

final readonly class ComparisonConfig
{
    /** @param list<string> $fields пути PropertyAccess: 'title', 'dftRange.tdsDft' */
    public function __construct(public array $fields)
    {
    }
}
