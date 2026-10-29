<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Infrastructure\EventHandler;

use App\Inquiries\Domain\Event\InquiryProcessed;
use App\Inquiries\Domain\Event\InquiryProcessingOutcome;
use App\Inquiries\Infrastructure\EventHandler\InquiryProcessedNotifier;
use App\Notifications\Domain\Event\InquiryProcessedNotification;
use App\Shared\Application\Event\EventBusInterface;
use App\Shared\Domain\Event\EventInterface;
use PHPUnit\Framework\TestCase;

final class InquiryProcessedNotifierTest extends TestCase
{
    public function test_maps_failed_domain_event_to_owner_notification(): void
    {
        $sent = $this->notify(new InquiryProcessed('inq-1', 'owner-ulid', InquiryProcessingOutcome::Failed, 'таймаут'));

        self::assertInstanceOf(InquiryProcessedNotification::class, $sent);
        self::assertSame('owner-ulid', $sent->ownerUlid());
        self::assertSame('inq-1', $sent->inquiryId());
        self::assertTrue($sent->isFailed());
        self::assertSame('таймаут', $sent->failureReason());
    }

    public function test_maps_recognized_as_not_failed(): void
    {
        $sent = $this->notify(new InquiryProcessed('inq-2', 'owner-ulid', InquiryProcessingOutcome::Recognized));

        self::assertInstanceOf(InquiryProcessedNotification::class, $sent);
        self::assertFalse($sent->isFailed());
    }

    /** Прогоняет переходник и возвращает событие, отданное в шину. */
    private function notify(InquiryProcessed $event): EventInterface
    {
        $captured = [];
        $bus = $this->createMock(EventBusInterface::class);
        $bus->method('execute')->willReturnCallback(static function (EventInterface ...$events) use (&$captured): void {
            $captured = $events;
        });

        (new InquiryProcessedNotifier($bus))($event);

        self::assertCount(1, $captured);

        return $captured[0];
    }
}
