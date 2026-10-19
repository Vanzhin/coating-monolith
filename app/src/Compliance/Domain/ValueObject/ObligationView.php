<?php

declare(strict_types=1);

namespace App\Compliance\Domain\ValueObject;

/**
 * Узкий срез обязанности человека, нужный {@see \App\Compliance\Domain\Service\ComplianceBucketResolver} для
 * расчёта бакета/статуса. Единственный носитель — read-модель {@see \App\Compliance\Domain\ReadModel\ObligationProjection}
 * (агрегат `ProfileCompliance` норму не материализует, Вариант B); интерфейс выделен, чтобы резолвер не был
 * завязан на конкретный класс read-модели.
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

    /**
     * Периодическая обязанность ({@see \App\Compliance\Domain\Type\ComplianceType::Periodic}) — резолвер считает
     * её отдельным ладдером (срок первичен: просрочка бьёт держащую норму, недовыдача — между Overdue и Soon).
     */
    public function isPeriodic(): bool;
}
