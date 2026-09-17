<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\AuditAction;
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\ChangeSet;
use App\Shared\Domain\Audit\FieldChange;
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    public function test_holds_data(): void
    {
        $e = new AuditEntry('id', 'App\\X', 'c1', AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'X', 'Y')), 'ulid', new \DateTimeImmutable());
        self::assertSame(['App\\X', 'c1', AuditAction::Updated, 'ulid'], [$e->entityClass(), $e->entityId(), $e->action(), $e->actorId()]);
        self::assertFalse($e->changes()->isEmpty());
    }
}
