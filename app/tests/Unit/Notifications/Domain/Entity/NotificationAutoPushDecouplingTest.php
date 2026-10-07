<?php

declare(strict_types=1);

namespace App\Tests\Unit\Notifications\Domain\Entity;

use App\Notifications\Domain\Entity\Notification;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Авто-пуш расцеплён: создание Notification больше НЕ поднимает доменного события доставки. Доставка
 * (inbox/push/email) идёт только через NotificationDispatcher по подпискам. Событие NotificationCreatedEvent удалено.
 */
final class NotificationAutoPushDecouplingTest extends TestCase
{
    public function test_creating_notification_raises_no_delivery_event(): void
    {
        $n = new Notification(Uuid::v7(), '01JQABCDEFGHJKMNPQRSTVWXYZ', 'текст', new \DateTimeImmutable());

        self::assertSame([], $n->pullEvents(), 'создание уведомления не поднимает доменных событий');
        self::assertFalse(
            class_exists('App\\Notifications\\Domain\\Event\\NotificationCreatedEvent'),
            'событие авто-пуша удалено',
        );
    }
}
