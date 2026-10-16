<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Repository;

use App\Compliance\Domain\Type\DocumentStatus;
use App\Shared\Domain\Repository\Pager;

/**
 * Bag-of-fields списка актов списания (кросс-организационный, для админа). Собственной логики не
 * носит — только поля, по которым {@see \App\Compliance\Infrastructure\Repository\WriteOffActRepository::findByFilter}
 * строит DQL-условия.
 */
readonly class WriteOffActFilter
{
    public function __construct(
        public Pager $pager,
        public ?string $organizationId = null,
        public ?string $requirementId = null,
        public ?DocumentStatus $status = null,
        public ?string $actNumber = null,
    ) {
    }
}
