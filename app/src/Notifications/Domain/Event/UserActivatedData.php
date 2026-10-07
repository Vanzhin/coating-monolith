<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Данные события «новый пользователь активирован» для рендера текста уведомления. */
interface UserActivatedData
{
    public function newUserEmail(): string;
}
