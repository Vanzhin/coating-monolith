<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

use App\Notifications\Domain\Type\NotificationType;

/**
 * Уведомляющее событие «новый пользователь активирован» — адресовано админу (Owner). Системный тип:
 * доставляется принудительно по всем каналам админа с адресом. Несёт email нового пользователя для текста.
 */
final readonly class UserActivatedNotification implements NotifiableEvent, OwnedNotification, UserActivatedData
{
    public function __construct(
        private string $adminUlid,
        private string $newUserEmail,
    ) {
    }

    public function notificationType(): NotificationType
    {
        return NotificationType::UserActivated;
    }

    public function ownerUlid(): string
    {
        return $this->adminUlid;
    }

    public function newUserEmail(): string
    {
        return $this->newUserEmail;
    }
}
