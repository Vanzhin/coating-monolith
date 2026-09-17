<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Query\GetClassAuditLog;

use App\Shared\Application\Query\Query;
use App\Shared\Domain\Aggregate\Collection\StringCollection;

final readonly class GetClassAuditLogQuery extends Query
{
    public function __construct(
        public string $entityClass,
        public StringCollection $actorIds = new StringCollection(),
        public StringCollection $entityIds = new StringCollection(),
        public int $page = 1,
    ) {
    }
}
