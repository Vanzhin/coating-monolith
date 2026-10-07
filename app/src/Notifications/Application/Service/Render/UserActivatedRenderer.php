<?php

declare(strict_types=1);

namespace App\Notifications\Application\Service\Render;

use App\Notifications\Domain\Event\NotifiableEvent;
use App\Notifications\Domain\Event\UserActivatedData;
use App\Notifications\Domain\Service\MessageRendererInterface;
use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Infrastructure\Exception\AppException;

/** Текст системного уведомления «новый пользователь». Событие обязано нести данные контракта UserActivatedData. */
final readonly class UserActivatedRenderer implements MessageRendererInterface
{
    public function type(): NotificationType
    {
        return NotificationType::UserActivated;
    }

    public function render(NotifiableEvent $event): string
    {
        if (!$event instanceof UserActivatedData) {
            throw new AppException('Событие user.activated не реализует UserActivatedData.');
        }

        return sprintf('Новый пользователь: %s', $event->newUserEmail());
    }
}
