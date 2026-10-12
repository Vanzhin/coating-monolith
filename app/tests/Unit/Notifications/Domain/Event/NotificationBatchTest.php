<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain\Event;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\NotificationBatch;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class NotificationBatchTest extends TestCase
{
    public function test_empty_batch_throws(): void
    {
        $this->expectException(AppException::class);
        new NotificationBatch();
    }

    public function test_keeps_events(): void
    {
        $batch = new NotificationBatch($this->event(), $this->event());

        self::assertCount(2, $batch->events());
    }

    private function event(): NotifiableEvent
    {
        return new class implements NotifiableEvent {
            public function notificationType(): NotificationType
            {
                return NotificationType::ComplianceDueSoon;
            }
        };
    }
}
