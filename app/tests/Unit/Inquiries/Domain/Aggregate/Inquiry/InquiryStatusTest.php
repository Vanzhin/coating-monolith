<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Domain\Aggregate\Inquiry;

use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use PHPUnit\Framework\TestCase;

final class InquiryStatusTest extends TestCase
{
    public function test_forward_path_allowed(): void
    {
        self::assertTrue(InquiryStatus::Received->canTransitionTo(InquiryStatus::Recognized));
        self::assertTrue(InquiryStatus::Recognized->canTransitionTo(InquiryStatus::Approved));
    }

    public function test_edit_loop_allowed(): void
    {
        // инженер правит параметры на распознанном — перераспознавание (тот же статус)
        self::assertTrue(InquiryStatus::Recognized->canTransitionTo(InquiryStatus::Recognized));
    }

    public function test_failure_from_working_states(): void
    {
        self::assertTrue(InquiryStatus::Received->canTransitionTo(InquiryStatus::Failed));
        self::assertTrue(InquiryStatus::Recognized->canTransitionTo(InquiryStatus::Failed));
    }

    public function test_failed_retries(): void
    {
        self::assertTrue(InquiryStatus::Failed->canTransitionTo(InquiryStatus::Recognized)); // ручная правка
        self::assertTrue(InquiryStatus::Failed->canTransitionTo(InquiryStatus::Received));   // повторный прогон
        self::assertFalse(InquiryStatus::Failed->canTransitionTo(InquiryStatus::Approved));
    }

    public function test_approved_is_terminal(): void
    {
        foreach (InquiryStatus::cases() as $to) {
            self::assertFalse(InquiryStatus::Approved->canTransitionTo($to), "Approved → {$to->value} должен быть запрещён");
        }
    }

    public function test_premature_jumps_forbidden(): void
    {
        self::assertFalse(InquiryStatus::Received->canTransitionTo(InquiryStatus::Approved));
    }

    public function test_every_status_has_label(): void
    {
        foreach (InquiryStatus::cases() as $status) {
            self::assertNotSame('', $status->label());
        }
    }

    public function test_processing_states(): void
    {
        self::assertTrue(InquiryStatus::Received->isProcessing());
        self::assertFalse(InquiryStatus::Recognized->isProcessing());
        self::assertFalse(InquiryStatus::Approved->isProcessing());
        self::assertFalse(InquiryStatus::Failed->isProcessing());
    }
}
