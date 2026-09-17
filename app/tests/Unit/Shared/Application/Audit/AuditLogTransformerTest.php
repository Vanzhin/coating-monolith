<?php

declare(strict_types=1);

namespace App\Tests\Unit\Shared\Application\Audit;

use App\Shared\Application\Audit\AuditLogTransformer;
use App\Shared\Domain\Audit\AuditAction;
use App\Shared\Domain\Audit\AuditEntry;
use App\Shared\Domain\Audit\AuditPolicyInterface;
use App\Shared\Domain\Audit\ChangeSet;
use App\Shared\Domain\Audit\FieldChange;
use PHPUnit\Framework\TestCase;

final class AuditLogTransformerTest extends TestCase
{
    /** @param array<string, string> $map */
    private function policy(array $map): AuditPolicyInterface
    {
        return new class($map) implements AuditPolicyInterface {
            /** @param array<string, string> $map */
            public function __construct(private array $map)
            {
            }

            public function trackedFields(string $entityClass): array
            {
                return $this->map;
            }

            public function invalidate(string $entityClass): void
            {
            }
        };
    }

    public function test_label_from_config_and_system_actor(): void
    {
        $t = new AuditLogTransformer($this->policy(['title' => 'Название']));
        $v = $t->view(new AuditEntry(
            'id',
            'App\\X',
            'c1',
            AuditAction::Updated,
            new ChangeSet(FieldChange::set('title', 'X', 'Y')),
            'system',
            new \DateTimeImmutable(),
        ));

        self::assertSame('Система', $v->actorLabel);
        self::assertSame('Название', $v->changes[0]->label);
    }

    public function test_nested_head_label_keeps_tail_and_ulid_actor(): void
    {
        $t = new AuditLogTransformer($this->policy(['minRecoatingInterval' => 'Дерево перекрытия']));
        $v = $t->view(new AuditEntry(
            'id',
            'App\\X',
            'c1',
            AuditAction::Updated,
            new ChangeSet(FieldChange::set('minRecoatingInterval.default', [1], [2])),
            'ulid-1',
            new \DateTimeImmutable(),
        ));

        self::assertSame('Дерево перекрытия · default', $v->changes[0]->label);
        self::assertSame('ulid-1', $v->actorLabel);
    }
}
