<?php

declare(strict_types=1);

namespace App\Compliance\Application\ReadModel;

use App\Compliance\Domain\Service\ComplianceStatusResolver;

/**
 * Выводит 4-статусный бакет дашборда из подписи документа + дат + текущего момента. Согласован с доменным
 * {@see ComplianceStatusResolver} (тот же порог {@see ComplianceStatusResolver::DUE_SOON_DAYS}); отличие — Red
 * доменного разбивается на Missing (не подписан/не выполнено) и Overdue (просрочено). Для материальных позиций
 * «на руках меньше нормы» ($norm/$held) — тоже Missing (требует выдачи), независимо от срока. Чистая функция,
 * зовётся на чтении.
 */
final class ComplianceBucketResolver
{
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
            return ComplianceBucket::Missing; // не выполнено
        }
        if (null === $nextDueAt) {
            return ComplianceBucket::Ok; // выполнено, повторного срока нет
        }
        if ($nextDueAt < $now) {
            return ComplianceBucket::Overdue;
        }
        if ($nextDueAt <= $now->modify('+'.ComplianceStatusResolver::DUE_SOON_DAYS.' days')) {
            return ComplianceBucket::Soon;
        }

        return ComplianceBucket::Ok;
    }
}
