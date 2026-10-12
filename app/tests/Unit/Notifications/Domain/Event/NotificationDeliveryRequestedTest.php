<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain\Event;

use App\Notifications\Domain\Event\NotificationDeliveryRequested;
use App\Notifications\Domain\Type\NotificationChannel;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;

final class NotificationDeliveryRequestedTest extends TestCase
{
    public function test_blank_message_throws(): void
    {
        $this->expectException(AppException::class);
        new NotificationDeliveryRequested('u-1', NotificationChannel::Inbox, '   ');
    }

    public function test_keeps_fields(): void
    {
        $d = new NotificationDeliveryRequested('u-1', NotificationChannel::Email, 'текст');

        self::assertSame('u-1', $d->userUlid);
        self::assertSame(NotificationChannel::Email, $d->channel);
        self::assertSame('текст', $d->message);
    }
}
