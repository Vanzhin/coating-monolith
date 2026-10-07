<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

use App\Notifications\Domain\Type\NotificationType;
use App\Shared\Domain\Event\EventInterface;

/** Доменное событие, подлежащее доставке как уведомление. Отдаёт свой тип из каталога. */
interface NotifiableEvent extends EventInterface
{
    public function notificationType(): NotificationType;
}
