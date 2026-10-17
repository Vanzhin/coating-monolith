<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Domain\Service;

use App\Compliance\Domain\Service\ComplianceBucketResolver;
use App\Compliance\Domain\Type\ComplianceBucket;
use App\Compliance\Domain\Type\ComplianceStatus;
use PHPUnit\Framework\TestCase;

/**
 * Единый ладдер состояния: {@see ComplianceBucketResolver::bucketFor()} (канонический 4-бакет) и производный
 * {@see ComplianceBucketResolver::statusFor()} (трёхцветный). Горизонт «подходит срок» — DUE_SOON_DAYS (60).
 * Вход — {@see ObligationView} (тест-дубль {@see FakeObligationView}), не 6 позиционных аргументов.
 */
final class ComplianceBucketResolverTest extends TestCase
{
    private ComplianceBucketResolver $r;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->r = new ComplianceBucketResolver();
        $this->now = new \DateTimeImmutable('2026-06-01'); // +60 дней = 2026-07-31
    }

    public function test_not_signed_is_missing_regardless_of_dates(): void
    {
        $row = FakeObligationView::inventoried(false, new \DateTimeImmutable('2027-01-01'), null, 0.0, new \DateTimeImmutable('2026-01-01'));
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor($row, $this->now));
    }

    public function test_signed_but_never_fulfilled_is_missing(): void
    {
        $noDueDate = FakeObligationView::inventoried(true, null, null, 0.0, null);
        $withDueDate = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-01-01'), null, 0.0, null);
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor($noDueDate, $this->now));
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor($withDueDate, $this->now));
    }

    public function test_fulfilled_without_next_due_is_ok(): void
    {
        $row = FakeObligationView::inventoried(true, null, null, 0.0, new \DateTimeImmutable('2026-01-01'));
        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor($row, $this->now));
    }

    public function test_past_due_is_overdue(): void
    {
        $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2026-05-20'), null, 0.0, new \DateTimeImmutable('2025-05-01'));
        self::assertSame(ComplianceBucket::Overdue, $this->r->bucketFor($row, $this->now));
    }

    public function test_within_horizon_is_soon(): void
    {
        $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2026-07-01'), null, 0.0, new \DateTimeImmutable('2025-07-01'));
        self::assertSame(ComplianceBucket::Soon, $this->r->bucketFor($row, $this->now));
    }

    public function test_boundary_60_days_is_soon(): void
    {
        // ровно now+60 = 2026-07-31 — ещё Soon (<=)
        $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2026-07-31'), null, 0.0, new \DateTimeImmutable('2025-07-31'));
        self::assertSame(ComplianceBucket::Soon, $this->r->bucketFor($row, $this->now));
    }

    public function test_just_past_horizon_is_ok(): void
    {
        // now+61 = 2026-08-01 — уже Ok
        $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2026-08-01'), null, 0.0, new \DateTimeImmutable('2026-01-01'));
        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor($row, $this->now));
    }

    public function test_far_future_is_ok(): void
    {
        $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-01-01'), null, 0.0, new \DateTimeImmutable('2026-01-01'));
        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor($row, $this->now));
    }

    public function test_under_issued_is_missing_even_if_in_date(): void
    {
        // активно, выдано вчера, срок далеко, НО на руках 1 из нормы 2 → недовыдано
        $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-05-31'), 2.0, 1.0, new \DateTimeImmutable('2026-05-31'));
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor($row, $this->now));
    }

    public function test_held_meets_norm_in_date_is_ok(): void
    {
        $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-05-31'), 2.0, 2.0, new \DateTimeImmutable('2026-05-31'));
        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor($row, $this->now));
    }

    public function test_status_is_derived_from_bucket(): void
    {
        // Missing/Overdue → Red, Soon → Yellow, Ok → Green (тот же вход, что и bucketFor выше)
        self::assertSame(
            ComplianceStatus::Red,
            $this->r->statusFor(FakeObligationView::inventoried(false, new \DateTimeImmutable('2027-01-01'), null, 0.0, new \DateTimeImmutable('2026-01-01')), $this->now),
        );
        self::assertSame(
            ComplianceStatus::Red,
            $this->r->statusFor(FakeObligationView::inventoried(true, new \DateTimeImmutable('2026-05-20'), null, 0.0, new \DateTimeImmutable('2025-05-01')), $this->now),
        );
        self::assertSame(
            ComplianceStatus::Yellow,
            $this->r->statusFor(FakeObligationView::inventoried(true, new \DateTimeImmutable('2026-07-01'), null, 0.0, new \DateTimeImmutable('2025-07-01')), $this->now),
        );
        self::assertSame(
            ComplianceStatus::Green,
            $this->r->statusFor(FakeObligationView::inventoried(true, null, null, 0.0, new \DateTimeImmutable('2026-01-01')), $this->now),
        );
        self::assertSame(
            ComplianceStatus::Red,
            $this->r->statusFor(FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-05-31'), 2.0, 1.0, new \DateTimeImmutable('2026-05-31')), $this->now),
        );
    }

    /** Анти-протухание: срок уже в прошлом даже без какого-либо нового события — ладдер решает по ДАТЕ, не по факту события. */
    public function test_overdue_when_next_due_in_past_without_any_event(): void
    {
        $now = new \DateTimeImmutable('2026-10-17');
        $row = FakeObligationView::inventoried(active: true, nextDueAt: new \DateTimeImmutable('2026-10-01'), norm: 1.0, held: 1.0);
        self::assertSame(ComplianceBucket::Overdue, (new ComplianceBucketResolver())->bucketFor($row, $now));
    }

    public function test_due_soon_within_60_days_but_ok_beyond(): void
    {
        $now = new \DateTimeImmutable('2026-10-17');
        $resolver = new ComplianceBucketResolver();
        $soon = FakeObligationView::inventoried(true, new \DateTimeImmutable('2026-12-10'), 1.0, 1.0); // +54д
        $ok = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-02-01'), 1.0, 1.0); // +107д
        self::assertSame(ComplianceBucket::Soon, $resolver->bucketFor($soon, $now));
        self::assertSame(ComplianceBucket::Ok, $resolver->bucketFor($ok, $now));
    }

    public function test_missing_when_held_below_norm(): void
    {
        $now = new \DateTimeImmutable('2026-10-17');
        $row = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-01-01'), norm: 2.0, held: 1.0);
        self::assertSame(ComplianceBucket::Missing, (new ComplianceBucketResolver())->bucketFor($row, $now)); // held<norm, эпсилон 1e-9
    }

    /**
     * Канонизация эпсилона (ЕДИНСТВЕННОЕ намеренное изменение поведения этой задачи): held, отличающийся от
     * нормы на величину МЕНЬШЕ эпсилона (погрешность floating-point), не считается недовыдачей — но и на
     * волос БОЛЬШЕ эпсилона уже Missing. Раньше резолвер сравнивал без эпсилона (`$held < $norm` ровно).
     */
    public function test_held_within_epsilon_of_norm_is_not_missing_but_just_outside_is(): void
    {
        $withinEpsilon = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-01-01'), 2.0, 2.0 - 1e-10);
        $outsideEpsilon = FakeObligationView::inventoried(true, new \DateTimeImmutable('2027-01-01'), 2.0, 2.0 - 1e-8);

        self::assertSame(ComplianceBucket::Ok, $this->r->bucketFor($withinEpsilon, $this->now));
        self::assertSame(ComplianceBucket::Missing, $this->r->bucketFor($outsideEpsilon, $this->now));
    }
}
