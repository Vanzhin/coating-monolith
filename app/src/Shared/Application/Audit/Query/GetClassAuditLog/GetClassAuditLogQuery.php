<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit\Query\GetClassAuditLog;

use App\Shared\Application\Query\Query;

final readonly class GetClassAuditLogQuery extends Query
{
    public function __construct(
        public string $entityClass,
        public ?string $actorId = null,
        public int $page = 1,
    ) {
    }
}
