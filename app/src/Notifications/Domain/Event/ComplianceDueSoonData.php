<?php

declare(strict_types=1);

namespace App\Notifications\Domain\Event;

/** Данные события «подходит срок выдачи СИЗ» для рендера текста уведомления. */
interface ComplianceDueSoonData
{
    public function employeeFio(): string;

    public function obligationLabel(): string;

    public function dueDate(): string;
}
