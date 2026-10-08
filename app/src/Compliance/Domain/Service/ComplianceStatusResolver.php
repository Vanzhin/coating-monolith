<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Service;

use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceStatus;

/**
 * ЕДИНЫЙ расчёт состояния обязанности из подписи документа + хранимых дат + нормы/наличия + текущего момента.
 * Состояние НЕ хранится (меняется от хода времени, а не по событиям) — это чистая функция, зовётся на чтении
 * (карточка, дашборд, проход уведомлений). Один ладдер — одно место правки.
 *
 * {@see bucketFor()} — канонический 4-бакет ({@see ComplianceBucket}); {@see statusFor()} — трёхцветный
 * светофор, ПРОИЗВОДНЫЙ от бакета ({@see ComplianceStatus::fromBucket()}). Второго ладдера нет.
 *
 * Правило: документ требования (человек×требование) не подписан ⇒ НЕ ИСПОЛНЕНО (Missing), независимо от сроков.
 * Для материальных позиций «на руках меньше нормы» — тоже Missing (недовыдано), независимо от срока. Только у
 * подписанного и обеспеченного количеством считаем срок-светофор по датам выдачи.
 */
final class ComplianceStatusResolver
{
    /** Горизонт «подходит срок» (дней) — общий для дашборда, карточки и прохода уведомлений. */
    public const DUE_SOON_DAYS = 60;

    public function bucketFor(
        bool $active,
        ?\DateTimeImmutable $lastFulfilledAt,
        ?\DateTimeImmutable $nextDueAt,
        \DateTimeImmutable $now,
        ?float $norm = null,
        float $held = 0.0,
    ): ComplianceBucket {
        if (!$active) {
            return ComplianceBucket::Missing; // документ не подписан — не исполнено
        }
        if (null !== $norm && $held < $norm) {
            return ComplianceBucket::Missing; // недовыдано по количеству — требует выдачи
        }
        if (null === $lastFulfilledAt) {
            return ComplianceBucket::Missing; // требуется, ни разу не выполнено
        }
        if (null === $nextDueAt) {
            return ComplianceBucket::Ok; // выполнено, повторного срока нет (однократно/по факту/без даты)
        }
        if ($nextDueAt < $now) {
            return ComplianceBucket::Overdue; // просрочено
        }
        if ($nextDueAt <= $now->modify('+'.self::DUE_SOON_DAYS.' days')) {
            return ComplianceBucket::Soon; // подходит срок
        }

        return ComplianceBucket::Ok;
    }

    /** Трёхцветный светофор (карточка/агрегат по человеку) — производное от {@see bucketFor()}. */
    public function statusFor(
        bool $active,
        ?\DateTimeImmutable $lastFulfilledAt,
        ?\DateTimeImmutable $nextDueAt,
        \DateTimeImmutable $now,
        ?float $norm = null,
        float $held = 0.0,
    ): ComplianceStatus {
        return ComplianceStatus::fromBucket($this->bucketFor($active, $lastFulfilledAt, $nextDueAt, $now, $norm, $held));
    }
}
