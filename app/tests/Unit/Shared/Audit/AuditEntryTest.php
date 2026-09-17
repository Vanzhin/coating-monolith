<?php
declare(strict_types=1);
namespace App\Tests\Unit\Shared\Audit;

use App\Shared\Domain\Audit\{AuditAction, AuditEntry, ChangeSet, FieldChange};
use PHPUnit\Framework\TestCase;

final class AuditEntryTest extends TestCase
{
    public function testHoldsData(): void
    {
        $e = new AuditEntry('id', 'App\\X', 'c1', AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'X', 'Y')), 'ulid', new \DateTimeImmutable());
        self::assertSame(['App\\X', 'c1', AuditAction::Updated, 'ulid'], [$e->entityClass(), $e->entityId(), $e->action(), $e->actorId()]);
        self::assertFalse($e->changes()->isEmpty());
    }
}
