<?php

declare(strict_types=1);

namespace App\Compliance\Application\UseCase\Query\Dashboard;

use App\Compliance\Application\ReadModel\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceType;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/** Фильтр дашборда «Соответствие» (bag-of-fields). Owner-скоуп применяет хендлер, не фильтр. */
final class ComplianceDashboardFilter
{
    public function __construct(
        public ?string $q = null,
        public StringCollection $departmentIds = new StringCollection(),
        public StringCollection $positionIds = new StringCollection(),
        public ?ComplianceType $type = null,
        public bool $onlyProblems = false,
        public ?ComplianceBucket $statusBucket = null,
        public StringCollection $profileIds = new StringCollection(),
        public ?Pager $pager = null,
    ) {
    }
}
