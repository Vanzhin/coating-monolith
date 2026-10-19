<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Service;

use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\ValueObject\ObligationView;

/**
 * ЕДИНЫЙ расчёт состояния обязанности из подписи документа + хранимых дат + нормы/наличия + текущего момента.
 * Состояние НЕ хранится (меняется от хода времени, а не по событиям) — это чистая функция, зовётся на чтении
 * (карточка, дашборд, проход уведомлений). Один ладдер — одно место правки. Вход — {@see ObligationView},
 * единственный носитель — read-проекция {@see \App\Compliance\Domain\ReadModel\ObligationProjection}
 * (агрегат норму не материализует, Вариант B).
 *
 * {@see bucketFor()} — канонический 4-бакет ({@see ComplianceBucket}); {@see statusFor()} — трёхцветный
 * светофор, ПРОИЗВОДНЫЙ от бакета ({@see ComplianceStatus::fromBucket()}). Второго ладдера нет.
 *
 * Правило: документ требования (человек×требование) не подписан ⇒ НЕ ИСПОЛНЕНО (Missing), независимо от сроков.
 * Для материальных позиций «на руках меньше нормы» — тоже Missing (недовыдано), независимо от срока. Только у
 * подписанного и обеспеченного количеством считаем срок-светофор по датам выдачи. Сравнение held/norm — с
 * эпсилоном (floating-point погрешность сумм по фактам не должна читаться как недовыдача).
 */
final class ComplianceBucketResolver
{
    /** Горизонт «подходит срок» (дней) — общий для дашборда, карточки и прохода уведомлений. */
    public const DUE_SOON_DAYS = 60;

    /**
     * Допуск сравнения held/norm — ЕДИНСТВЕННЫЙ источник порога для статуса/бакета. Публичная, потому что
     * UI-тон «на руках» ({@see \App\Compliance\Application\UseCase\Query\GetIssuePage\GetIssuePageQueryHandler::heldTone()})
     * несёт свой нюанс (перевыдача сверх нормы — отдельный цвет) и не сводится к одному из 4 бакетов, но порог
     * должен быть ровно тем же, не вторым хардкодом.
     */
    public const QUANTITY_EPSILON = 1e-9;

    public function bucketFor(ObligationView $obligation, \DateTimeImmutable $now): ComplianceBucket
    {
        if (!$obligation->isActive()) {
            return ComplianceBucket::Missing; // документ не подписан — не исполнено
        }

        return $obligation->isPeriodic()
            ? $this->periodicBucketFor($obligation, $now)
            : $this->inventoriedBucketFor($obligation, $now);
    }

    /** Инвентарь (СИЗ) — недовыдача по количеству первична, срок считается только у обеспеченной позиции. */
    private function inventoriedBucketFor(ObligationView $obligation, \DateTimeImmutable $now): ComplianceBucket
    {
        $norm = $obligation->normQuantity();
        if (null !== $norm && $obligation->heldQuantity() < $norm - self::QUANTITY_EPSILON) {
            return ComplianceBucket::Missing; // недовыдано по количеству — требует выдачи
        }
        if (null === $obligation->lastFulfilledAt()) {
            return ComplianceBucket::Missing; // требуется, ни разу не выполнено
        }
        $nextDueAt = $obligation->nextDueAt();
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

    /**
     * Периодическая (инструктаж/расходник раз в N) — срок первичен: просрочка бьёт даже держащую норму
     * позицию (доввыдача после срока не спасает), недовыдача проверяется МЕЖДУ Overdue и Soon — иначе
     * «недовыдано, но срок скоро» ошибочно читалось бы как Soon вместо Missing (Ruling 1).
     *
     * Инструктаж ({@see ObligationView::isInstructionLike()}) — ИСКЛЮЧЕНИЕ из ветки недовыдачи: у него количество
     * не инвариант (норма «1 шт.» нужна только модели, форма выдачи его не запрашивает и шлёт held=0), контроль
     * ЦЕЛИКОМ по дате (не выполнено / просрочено / скоро / в норме). Без этого исключения прошедший инструктаж
     * читался бы как вечный Missing (held 0 < норма 1).
     */
    private function periodicBucketFor(ObligationView $obligation, \DateTimeImmutable $now): ComplianceBucket
    {
        if (null === $obligation->lastFulfilledAt()) {
            return ComplianceBucket::Missing; // требуется, ни разу не выполнено
        }
        $nextDueAt = $obligation->nextDueAt();
        if (null !== $nextDueAt && $nextDueAt < $now) {
            return ComplianceBucket::Overdue; // просрочено — независимо от количества на руках
        }
        $norm = $obligation->normQuantity();
        if (!$obligation->isInstructionLike() && null !== $norm && $obligation->heldQuantity() < $norm - self::QUANTITY_EPSILON) {
            return ComplianceBucket::Missing; // недовыдано по количеству, срок пока не прошёл (инструктаж не проверяем — см. докблок)
        }
        if (null === $nextDueAt) {
            return ComplianceBucket::Ok;
        }
        if ($nextDueAt <= $now->modify('+'.self::DUE_SOON_DAYS.' days')) {
            return ComplianceBucket::Soon; // подходит срок
        }

        return ComplianceBucket::Ok;
    }

    /** Трёхцветный светофор (карточка/агрегат по человеку) — производное от {@see bucketFor()}. */
    public function statusFor(ObligationView $obligation, \DateTimeImmutable $now): ComplianceStatus
    {
        return ComplianceStatus::fromBucket($this->bucketFor($obligation, $now));
    }
}
