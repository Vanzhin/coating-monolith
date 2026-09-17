<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use App\Shared\Domain\Audit\AuditAction;

final readonly class AuditLogView
{
    /** @param list<FieldChangeView> $changes */
    public function __construct(
        public AuditAction $action,
        public string $actorId,
        public string $actorLabel,
        public \DateTimeImmutable $occurredAt,
        public string $entityId,
        public array $changes,
    ) {
    }
}
