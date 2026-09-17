<?php

declare(strict_types=1);

namespace App\Shared\Domain\Audit;

/** Запись аудита. Append-only. seq генерит БД (identity), ORM читает его обратно после INSERT. */
class AuditEntry
{
    private ?int $seq = null;

    public function __construct(
        private readonly string $id,
        private readonly string $entityClass,
        private readonly string $entityId,
        private readonly AuditAction $action,
        private readonly ChangeSet $changes,
        private readonly string $actorId,
        private readonly \DateTimeImmutable $occurredAt,
    ) {
    }

    public function id(): string
    {
        return $this->id;
    }

    public function entityClass(): string
    {
        return $this->entityClass;
    }

    public function entityId(): string
    {
        return $this->entityId;
    }

    public function action(): AuditAction
    {
        return $this->action;
    }

    public function changes(): ChangeSet
    {
        return $this->changes;
    }

    public function actorId(): string
    {
        return $this->actorId;
    }

    public function occurredAt(): \DateTimeImmutable
    {
        return $this->occurredAt;
    }

    public function seq(): ?int
    {
        return null === $this->seq ? null : (int) $this->seq;
    }
}
