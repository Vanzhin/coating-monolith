<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

use App\Notifications\Domain\Type\NotificationChannel;
use App\Shared\Domain\Event\EventInterface;
use App\Shared\Infrastructure\Exception\AppException;

/**
 * Одна отправка: готовый текст получателю по одному каналу. Одна отправка — одно сообщение очереди, поэтому
 * сбой/ретрай одного канала не трогает остальные. Текст уже сформирован раскладкой.
 */
final readonly class NotificationDeliveryRequested implements EventInterface
{
    public function __construct(
        public string $userUlid,
        public NotificationChannel $channel,
        public string $message,
    ) {
        if ('' === trim($message)) {
            throw new AppException('Пустой текст уведомления.');
        }
    }
}
