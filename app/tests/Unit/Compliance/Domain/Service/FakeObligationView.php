<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Service;

use App\Compliance\Domain\ValueObject\ObligationView;

/** Тест-дубль {@see ObligationView} для резолвера — без агрегата/ORM, только поля ладдера. */
final class FakeObligationView implements ObligationView
{
    private function __construct(
        private readonly bool $active,
        private readonly ?\DateTimeImmutable $lastFulfilledAt,
        private readonly ?\DateTimeImmutable $nextDueAt,
        private readonly ?float $norm,
        private readonly float $held,
        private readonly bool $periodic,
    ) {
    }

    /** Обязанность с «выдачей в прошлом» по умолчанию — чтобы тест фокусировался на дате следующего срока/норме. */
    public static function inventoried(
        bool $active,
        ?\DateTimeImmutable $nextDueAt,
        ?float $norm,
        float $held,
        ?\DateTimeImmutable $lastFulfilledAt = new \DateTimeImmutable('2020-01-01'),
    ): self {
        return new self($active, $lastFulfilledAt, $nextDueAt, $norm, $held, false);
    }

    /** Периодическая обязанность — {@see ObligationView::isPeriodic()} true, резолвер считает её ладдером «срок первичен». */
    public static function periodic(
        bool $active,
        ?\DateTimeImmutable $lastFulfilledAt,
        ?\DateTimeImmutable $nextDueAt,
        ?float $norm,
        float $held,
    ): self {
        return new self($active, $lastFulfilledAt, $nextDueAt, $norm, $held, true);
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function lastFulfilledAt(): ?\DateTimeImmutable
    {
        return $this->lastFulfilledAt;
    }

    public function nextDueAt(): ?\DateTimeImmutable
    {
        return $this->nextDueAt;
    }

    public function normQuantity(): ?float
    {
        return $this->norm;
    }

    public function heldQuantity(): float
    {
        return $this->held;
    }

    public function isPeriodic(): bool
    {
        return $this->periodic;
    }
}
