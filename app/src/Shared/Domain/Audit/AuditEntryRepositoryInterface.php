<?php

declare(strict_types=1);

namespace App\Shared\Domain\Audit;

use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Domain\Repository\Pager;

interface AuditEntryRepositoryInterface
{
    /** @return list<AuditEntry> */
    public function forEntity(string $entityClass, string $entityId, Pager $pager): array;

    /** @return list<AuditEntry> */
    public function forClass(string $entityClass, StringCollection $actorIds, StringCollection $entityIds, Pager $pager): array;
}
