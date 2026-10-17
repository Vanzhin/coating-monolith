<?php

declare(strict_types=1);

namespace App\Compliance\Domain\Service;

use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceStatus;
use App\Compliance\Domain\ValueObject\ObligationView;

/**
 * ЕДИНЫЙ расчёт состояния обязанности из подписи документа + хранимых дат + нормы/наличия + текущего момента.
 * Состояние НЕ хранится (меняется от хода времени, а не по событиям) — это чистая функция, зовётся на чтении
 * (карточка, дашборд, проход уведомлений). Один ладдер — одно место правки. Вход — {@see ObligationView}:
 * резолверу не важно, пришла ли обязанность из агрегата ({@see \App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation})
 * или из read-проекции ({@see \App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection}).
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

    /** Допуск сравнения held/norm — значение как у {@see \App\Compliance\Infrastructure\Controller\Fulfillment\IssueAction::heldTone()} (UI-тон бейджа), но это ЕДИНСТВЕННЫЙ источник порога для статуса/бакета. */
    private const QUANTITY_EPSILON = 1e-9;

    public function bucketFor(ObligationView $obligation, \DateTimeImmutable $now): ComplianceBucket
    {
        if (!$obligation->isActive()) {
            return ComplianceBucket::Missing; // документ не подписан — не исполнено
        }
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

    /** Трёхцветный светофор (карточка/агрегат по человеку) — производное от {@see bucketFor()}. */
    public function statusFor(ObligationView $obligation, \DateTimeImmutable $now): ComplianceStatus
    {
        return ComplianceStatus::fromBucket($this->bucketFor($obligation, $now));
    }
}
