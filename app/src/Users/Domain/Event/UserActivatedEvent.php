<?php

declare(strict_types=1);

namespace App\Users\Domain\Event;

use App\Shared\Domain\Event\EventInterface;

/**
 * Пользователь стал активным (подтвердил первый канал). Несёт только ulid — обработчик
 * перечитывает пользователя из БД (защита от publish-before-commit).
 */
class UserActivatedEvent implements EventInterface
{
    public function __construct(public string $userId)
    {
    }
}
