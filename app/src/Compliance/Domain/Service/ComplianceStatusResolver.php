<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Service;

use App\Compliance\Domain\Type\ComplianceStatus;

/**
 * Выводит светофор обязанности из состояния подписи документа + хранимых дат + текущего момента. Статус НЕ
 * хранится (меняется от хода времени, а не по событиям), поэтому это чистая функция, зовётся на чтении
 * (карточка, дашборд, алерт).
 *
 * Правило: документ требования (человек×требование) не подписан ⇒ требование НЕ ИСПОЛНЕНО (Red), независимо
 * от сроков. Для материальных позиций «на руках меньше нормы» — тоже Red, независимо от срока (недовыдано).
 * Только у подписанного и невыданного-ниже-нормы считаем срок-светофор по датам выдачи.
 */
final class ComplianceStatusResolver
{
    public const DUE_SOON_DAYS = 14;

    public function statusFor(
        bool $active,
        ?\DateTimeImmutable $lastFulfilledAt,
        ?\DateTimeImmutable $nextDueAt,
        \DateTimeImmutable $now,
        ?float $norm = null,
        float $held = 0.0,
    ): ComplianceStatus {
        if (!$active) {
            return ComplianceStatus::Red; // документ не подписан — требование не исполнено
        }
        if (null !== $norm && $held < $norm) {
            return ComplianceStatus::Red; // недовыдано по количеству
        }
        if (null === $lastFulfilledAt) {
            return ComplianceStatus::Red; // требуется, ни разу не выполнено
        }
        if (null === $nextDueAt) {
            return ComplianceStatus::Green; // выполнено, повторного срока нет (однократно/по факту/без даты)
        }
        if ($nextDueAt < $now) {
            return ComplianceStatus::Red; // просрочено
        }
        if ($nextDueAt <= $now->modify('+'.self::DUE_SOON_DAYS.' days')) {
            return ComplianceStatus::Yellow; // подходит срок
        }

        return ComplianceStatus::Green;
    }
}
