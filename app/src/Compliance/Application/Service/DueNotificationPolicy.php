<?php

declare(strict_types=1);

namespace App\Compliance\Application\Service;

use App\Compliance\Domain\Type\ComplianceBucket;

/**
 * Решение прохода: слать ли уведомление по обязанности. ЕДИНАЯ точка политики антифлуда — меняется здесь,
 * не размазывается по сканеру. Вход: прежнее известное состояние (маркер), текущее состояние, когда слали
 * в последний раз, now.
 */
interface DueNotificationPolicy
{
    public function shouldNotify(?ComplianceBucket $previous, ComplianceBucket $current, ?\DateTimeImmutable $lastNotifiedAt, \DateTimeImmutable $now): bool;
}
