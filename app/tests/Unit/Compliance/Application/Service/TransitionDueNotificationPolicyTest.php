<?php

declare(strict_types=1);

namespace App\Tests\Unit\Compliance\Application\Service;

use App\Compliance\Application\Service\TransitionDueNotificationPolicy;
use App\Compliance\Domain\Type\ComplianceBucket;
use PHPUnit\Framework\TestCase;

final class TransitionDueNotificationPolicyTest extends TestCase
{
    private TransitionDueNotificationPolicy $policy;
    private \DateTimeImmutable $now;

    protected function setUp(): void
    {
        $this->policy = new TransitionDueNotificationPolicy();
        $this->now = new \DateTimeImmutable('2026-06-01');
    }

    public function test_notifies_on_first_entering_soon(): void
    {
        // нет маркера (previous=null → базово Ok) и стало Soon → ухудшение → шлём
        self::assertTrue($this->policy->shouldNotify(null, ComplianceBucket::Soon, null, $this->now));
    }

    public function test_notifies_on_worsening_soon_to_overdue(): void
    {
        self::assertTrue($this->policy->shouldNotify(ComplianceBucket::Soon, ComplianceBucket::Overdue, $this->now, $this->now));
    }

    public function test_notifies_on_missing_to_soon(): void
    {
        // Missing — ось «оформление», не «срок»: выдали карточку с близким/прошедшим сроком → Soon/Overdue
        // должно уведомить (не считать Missing «хуже», как в дашборд-severity).
        self::assertTrue($this->policy->shouldNotify(ComplianceBucket::Missing, ComplianceBucket::Soon, null, $this->now));
    }

    public function test_notifies_on_missing_to_overdue(): void
    {
        self::assertTrue($this->policy->shouldNotify(ComplianceBucket::Missing, ComplianceBucket::Overdue, null, $this->now));
    }

    public function test_silent_when_unchanged_soon(): void
    {
        self::assertFalse($this->policy->shouldNotify(ComplianceBucket::Soon, ComplianceBucket::Soon, $this->now, $this->now));
    }

    public function test_silent_when_unchanged_overdue(): void
    {
        self::assertFalse($this->policy->shouldNotify(ComplianceBucket::Overdue, ComplianceBucket::Overdue, $this->now, $this->now));
    }

    public function test_silent_on_improvement(): void
    {
        self::assertFalse($this->policy->shouldNotify(ComplianceBucket::Overdue, ComplianceBucket::Ok, $this->now, $this->now));
    }

    public function test_ok_and_missing_never_notify_via_this_digest(): void
    {
        // этот дайджест сроков про Ok/Missing не уведомляет (Missing — отдельная история: разрыв оформления)
        self::assertFalse($this->policy->shouldNotify(null, ComplianceBucket::Ok, null, $this->now));
        self::assertFalse($this->policy->shouldNotify(ComplianceBucket::Soon, ComplianceBucket::Missing, null, $this->now));
    }
}
