<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain\Entity;

use App\Notifications\Domain\Entity\Notification;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class NotificationTest extends TestCase
{
    public function test_new_notification_is_unread(): void
    {
        $notification = $this->notification('Покрытие обновлено');

        self::assertFalse($notification->isRead());
        self::assertNull($notification->getReadAt());
        self::assertSame('Покрытие обновлено', $notification->getMessage());
    }

    public function test_empty_message_is_rejected(): void
    {
        $this->expectException(AppException::class);
        $this->notification('');
    }

    public function test_mark_read_sets_read_state(): void
    {
        $notification = $this->notification('текст');

        $notification->markRead();

        self::assertTrue($notification->isRead());
        self::assertNotNull($notification->getReadAt());
    }

    public function test_mark_read_is_idempotent(): void
    {
        $notification = $this->notification('текст');
        $notification->markRead();
        $firstReadAt = $notification->getReadAt();

        $notification->markRead();

        self::assertSame($firstReadAt, $notification->getReadAt());
    }

    private function notification(string $message): Notification
    {
        return new Notification(Uuid::v7(), '01JQABCDEFGHJKMNPQRSTVWXYZ', $message, new \DateTimeImmutable());
    }
}
