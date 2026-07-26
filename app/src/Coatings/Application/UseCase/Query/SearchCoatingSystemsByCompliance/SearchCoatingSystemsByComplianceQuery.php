<?php

declare(strict_types=1);

namespace App\Coatings\Application\UseCase\Query\SearchCoatingSystemsByCompliance;

use App\Coatings\Domain\Aggregate\CoatingSystem\ComplianceStandard;
use App\Coatings\Domain\Aggregate\CoatingSystem\Substrate;
use App\Shared\Application\Query\Query;

readonly class SearchCoatingSystemsByComplianceQuery extends Query
{
    public function __construct(
        public ComplianceStandard $standard,
        public string $category,
        public string $durability,
        public ?Substrate $substrate,
        public int $page,
        public int $perPage,
    ) {
    }
}
