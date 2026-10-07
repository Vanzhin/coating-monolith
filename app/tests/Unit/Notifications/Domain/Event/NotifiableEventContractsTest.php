<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain\Event;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\SubjectNotification;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Event\EventInterface;
use PHPUnit\Framework\TestCase;

final class NotifiableEventContractsTest extends TestCase
{
    public function test_event_implements_contracts(): void
    {
        $e = new class implements NotifiableEvent, SubjectNotification {
            public function notificationType(): NotificationType
            {
                return NotificationType::ComplianceDueSoon;
            }

            public function subjectProfileId(): string
            {
                return 'p-1';
            }
        };
        self::assertInstanceOf(EventInterface::class, $e);
        self::assertSame(NotificationType::ComplianceDueSoon, $e->notificationType());
        self::assertSame('p-1', $e->subjectProfileId());
    }
}
