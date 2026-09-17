<?php

declare(strict_types=1);

namespace App\Shared\Application\Audit;

use App\Shared\Domain\Audit\ActorResolverInterface;
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\FieldChange;

/** AuditEntry → view-DTO. Подпись поля — из карты TrackedClass.fields; нет ключа → сам path. */
final class AuditLogTransformer
{
    public function __construct(
        private readonly AuditPolicyInterface $policy,
        private readonly ActorResolverInterface $actorResolver,
    ) {
    }

    public function view(AuditEntry $e): AuditLogView
    {
        $labels = $this->policy->trackedFields($e->entityClass());
        $changes = array_map(
            fn (FieldChange $c): FieldChangeView => new FieldChangeView($c->op, $this->label($labels, $c->path), $c->path, $c->old, $c->new),
            $e->changes()->all(),
        );

        return new AuditLogView(
            $e->action(),
            $e->actorId(),
            $this->actorResolver->resolve($e->actorId()),
            $e->occurredAt(),
            $e->entityId(),
            $changes,
        );
    }

    /** @param array<string, string> $labels */
    private function label(array $labels, string $path): string
    {
        $head = explode('.', $path)[0];
        $tail = substr($path, strlen($head) + 1);
        $headLabel = $labels[$head] ?? $head;

        return '' === $tail ? $headLabel : $headLabel.' · '.$tail;
    }
}
