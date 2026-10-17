<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject;

/**
 * Узкий срез обязанности человека, нужный {@see \App\Compliance\Domain\Service\ComplianceBucketResolver} для
 * расчёта бакета/статуса. Один интерфейс — два носителя: {@see \App\Compliance\Domain\Aggregate\ProfileCompliance\TrackedObligation}
 * (агрегат) и {@see \App\Compliance\Infrastructure\Database\ReadModel\ObligationProjection} (read-модель) —
 * резолверу не важно, откуда пришли данные, лишь бы форма была эта.
 */
interface ObligationView
{
    public function isActive(): bool;

    public function lastFulfilledAt(): ?\DateTimeImmutable;

    public function nextDueAt(): ?\DateTimeImmutable;

    /** Требуемое количество (норма). `null` — нет количественной нормы (например, нематериальное обязательство). */
    public function normQuantity(): ?float;

    /** Количество на руках (для материальных обязательств; у нематериальных не используется резолвером). */
    public function heldQuantity(): float;
}
