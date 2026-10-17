<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Type\ComplianceType;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

/**
 * Bag-of-fields списка строк проекции обязанностей (мониторинг/дашборд, кросс-персон). Собственной
 * логики не несёт — только поля, по которым
 * {@see \App\Compliance\Infrastructure\Repository\ObligationProjectionRepository::findByFilter} строит DQL-условия.
 */
final readonly class ObligationProjectionFilter
{
    public function __construct(
        public Pager $pager,
        public ?StringCollection $profileIds = null,
        public ?string $requirementId = null,
        public ?string $departmentId = null,
        public ?ComplianceType $type = null,
        public ?bool $active = null,
        public ?\DateTimeImmutable $dueBefore = null,
    ) {
    }
}
