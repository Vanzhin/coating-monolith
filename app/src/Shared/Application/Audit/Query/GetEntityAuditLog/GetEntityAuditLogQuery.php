<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Query\GetEntityAuditLog;

use App\Shared\Application\Query\Query;

final readonly class GetEntityAuditLogQuery extends Query
{
    public function __construct(
        public string $entityClass,
        public string $entityId,
        public int $page = 1,
    ) {
    }
}
