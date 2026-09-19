<?php

declare(strict_types=1);

namespace App\Tests\Unit\Reports\Domain\Aggregate\Report;

use App\Reports\Domain\Aggregate\Report\Report;
use App\Reports\Domain\Aggregate\Report\ReportStatus;
use App\Reports\Domain\Aggregate\Report\ReportType;
use App\Shared\Domain\Service\UuidService;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class ReportTest extends TestCase
{
    private \DateTimeImmutable $now;
    private string $owner;

    protected function setUp(): void
    {
        $this->now = new \DateTimeImmutable('2026-01-01 10:00:00');
        $this->owner = UuidService::generateUlid();
    }

    private function report(): Report
    {
        return new Report(Uuid::v7(), $this->owner, ReportType::TrialApplication, $this->now);
    }

    public function test_new_report_is_created_and_owned(): void
    {
        $report = $this->report();
        self::assertSame(ReportStatus::Created, $report->getStatus());
        self::assertTrue($report->isOwnedBy($this->owner));
        self::assertFalse($report->isOwnedBy(UuidService::generateUlid()));
    }

    public function test_happy_path_to_approved(): void
    {
        $report = $this->report();
        $report->startWork($this->now);
        $report->submitForReview($this->now);
        $report->approve($this->now);

        self::assertSame(ReportStatus::Approved, $report->getStatus());
    }

    public function test_cannot_return_to_work_from_review(): void
    {
        $report = $this->report();
        $report->startWork($this->now);
        $report->submitForReview($this->now);

        // На проверке нельзя обратно в работу напрямую — только утвердить/отклонить.
        $this->expectException(AppException::class);
        $report->startWork($this->now);
    }

    public function test_rejected_goes_back_to_work_and_clears_reason(): void
    {
        $report = $this->report();
        $report->startWork($this->now);
        $report->submitForReview($this->now);
        $report->reject('Не заполнены приборы', $this->now);
        self::assertSame(ReportStatus::Rejected, $report->getStatus());
        self::assertSame('Не заполнены приборы', $report->getRejectionReason());

        $report->startWork($this->now); // доработка после отклонения
        self::assertSame(ReportStatus::InWork, $report->getStatus());
        self::assertNull($report->getRejectionReason()); // причина снята
    }

    public function test_cannot_submit_directly_from_created(): void
    {
        $report = $this->report();

        // Создан → На проверке напрямую нельзя (только через В работе).
        $this->expectException(AppException::class);
        $report->submitForReview($this->now);
    }

    public function test_approved_is_frozen_for_transitions(): void
    {
        $report = $this->report();
        $report->startWork($this->now);
        $report->submitForReview($this->now);
        $report->approve($this->now);

        $this->expectException(AppException::class);
        $report->startWork($this->now);
    }

    public function test_approved_is_frozen_for_header_edit(): void
    {
        $report = $this->report();
        $report->startWork($this->now);
        $report->submitForReview($this->now);
        $report->approve($this->now);

        $this->expectException(AppException::class);
        $report->updateHeader(null, 'АКТ-1', $this->now);
    }

    public function test_repeated_action_is_noop(): void
    {
        $report = $this->report();
        $report->startWork($this->now);
        $report->startWork($this->now); // уже В работе — no-op

        self::assertSame(ReportStatus::InWork, $report->getStatus());
    }
}
