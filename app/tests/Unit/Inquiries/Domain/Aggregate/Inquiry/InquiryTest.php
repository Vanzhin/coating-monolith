<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Domain\Aggregate\Inquiry;

use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Event\InquirySubmitted;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

final class InquiryTest extends TestCase
{
    private const NOW = '2026-01-01 10:00:00';

    public function test_rejects_empty_input(): void
    {
        $this->expectException(AppException::class);
        new Inquiry(Uuid::v4(), InquiryType::SystemSelection, 'owner', '   ', new StringCollection(), $this->now());
    }

    public function test_attachments_only_is_valid(): void
    {
        $inquiry = new Inquiry(Uuid::v4(), InquiryType::SystemSelection, 'owner', '', new StringCollection('file-1'), $this->now());

        self::assertSame(InquiryStatus::Received, $inquiry->getStatus());
        self::assertSame(['file-1'], $inquiry->getAttachmentFileIds()->getList());
    }

    public function test_id_is_rfc4122(): void
    {
        $id = Uuid::v4();
        $inquiry = new Inquiry($id, InquiryType::SystemSelection, 'owner', 'текст', new StringCollection(), $this->now());

        self::assertSame($id->toRfc4122(), $inquiry->getId());
    }

    public function test_happy_lifecycle_recognize_approve(): void
    {
        $inquiry = $this->received();

        $inquiry->recognize(
            RecognizedParameters::recognized(['environment' => 'immersion', 'substanceIds' => ['s1']]),
            new ParameterSource(ParameterStrategy::Model, ['model' => 'qwen']),
            $this->now(),
        );
        self::assertSame(InquiryStatus::Recognized, $inquiry->getStatus());
        self::assertSame(ParameterStrategy::Model, $inquiry->getParameterSource()?->strategy);

        $inquiry->approve('engineer-ulid', $this->now());
        self::assertTrue($inquiry->isApproved());
        self::assertSame('engineer-ulid', $inquiry->getVerification()?->verifiedBy);
    }

    public function test_premature_approve_rejected(): void
    {
        $inquiry = $this->received();

        $this->expectException(AppException::class);
        $inquiry->approve('engineer', $this->now());
    }

    public function test_approved_is_frozen(): void
    {
        $inquiry = $this->approved();

        $this->expectException(AppException::class);
        $inquiry->recognize(RecognizedParameters::recognized([]), new ParameterSource(ParameterStrategy::Manual), $this->now());
    }

    public function test_fail_records_reason(): void
    {
        $inquiry = $this->received();
        $inquiry->fail('Модель недоступна', $this->now());

        self::assertSame(InquiryStatus::Failed, $inquiry->getStatus());
        self::assertSame('Модель недоступна', $inquiry->getFailureReason());
    }

    public function test_failed_can_be_retried_by_recognizing(): void
    {
        $inquiry = $this->received();
        $inquiry->fail('timeout', $this->now());

        $inquiry->recognize(RecognizedParameters::recognized([]), new ParameterSource(ParameterStrategy::Model), $this->now());
        self::assertSame(InquiryStatus::Recognized, $inquiry->getStatus());
    }

    public function test_engineer_edit_loop_reparametrizes(): void
    {
        $inquiry = $this->received();
        $inquiry->recognize(RecognizedParameters::recognized(['standard' => 'iso12944']), new ParameterSource(ParameterStrategy::Model), $this->now());

        // Инженер поправил параметры на распознанном → перераспознавание (Recognized → Recognized, Manual).
        $inquiry->recognize(RecognizedParameters::recognized(['standard' => 'iso12944', 'environment' => 'immersion']), new ParameterSource(ParameterStrategy::Manual), $this->now());
        self::assertSame(InquiryStatus::Recognized, $inquiry->getStatus());
        self::assertSame(ParameterStrategy::Manual, $inquiry->getParameterSource()?->strategy);

        $inquiry->approve(null, $this->now());
        self::assertTrue($inquiry->isApproved());
    }

    public function test_edit_parameters_marks_only_changed_fields_manual(): void
    {
        $inquiry = $this->received();
        $inquiry->recognize(
            RecognizedParameters::recognized(['environment' => 'immersion', 'standard' => 'iso12944']),
            new ParameterSource(ParameterStrategy::Model, ['model' => 'qwen']),
            $this->now(),
        );

        // инженер поправил только environment
        $inquiry->editParameters(['environment' => 'special', 'standard' => 'iso12944'], $this->now());

        $params = $inquiry->getRecognizedParameters();
        self::assertNotNull($params);
        self::assertSame('special', $params->toArray()['environment']);
        self::assertSame(ParameterStrategy::Manual, $params->originOf('environment'));  // правлен человеком
        self::assertSame(ParameterStrategy::Model, $params->originOf('standard'));       // не тронут — остался модельным
        self::assertSame(['environment'], $params->manuallyEditedKeys());
        self::assertSame(ParameterStrategy::Model, $inquiry->getParameterSource()?->strategy); // базовая стратегия не затёрта
        self::assertSame(InquiryStatus::Recognized, $inquiry->getStatus());
    }

    public function test_edit_parameters_from_scratch_is_fully_manual(): void
    {
        $inquiry = $this->received();
        $inquiry->fail('не распознал', $this->now());

        $inquiry->editParameters(['environment' => 'immersion'], $this->now());

        self::assertSame(InquiryStatus::Recognized, $inquiry->getStatus());
        self::assertSame(ParameterStrategy::Manual, $inquiry->getParameterSource()?->strategy);
        self::assertSame(['environment'], $inquiry->getRecognizedParameters()?->manuallyEditedKeys());
    }

    public function test_raises_submitted_event_on_creation(): void
    {
        $events = $this->received()->pullEvents();

        self::assertCount(1, $events);
        self::assertInstanceOf(InquirySubmitted::class, $events[0]);
    }

    public function test_recognize_clears_prior_failure_reason_on_retry(): void
    {
        $inquiry = $this->received();
        $inquiry->fail('модель недоступна', $this->now());

        $inquiry->recognize(RecognizedParameters::recognized([]), new ParameterSource(ParameterStrategy::Model), $this->now());

        self::assertNull($inquiry->getFailureReason());
    }

    public function test_set_comment_editable_even_when_approved(): void
    {
        $inquiry = $this->approved();
        self::assertSame('', $inquiry->getComment());

        $inquiry->setComment('  перезвонил клиенту, ждём ответа  ', $this->now());

        self::assertSame('перезвонил клиенту, ждём ответа', $inquiry->getComment());
    }

    public function test_set_comment_rejects_over_limit(): void
    {
        $inquiry = $this->received();

        $this->expectException(AppException::class);
        $inquiry->setComment(str_repeat('я', 2001), $this->now());
    }

    public function test_note_warnings_replaces(): void
    {
        $inquiry = $this->received();
        $inquiry->noteWarnings('первое предупреждение');
        $inquiry->noteWarnings('вещество «уайт-спирит» не в реестре', 'файл №2 — скан');

        self::assertSame(['вещество «уайт-спирит» не в реестре', 'файл №2 — скан'], $inquiry->getWarnings());
    }

    public function test_retry_from_failed_resets_to_received_and_clears(): void
    {
        $inquiry = $this->received();
        $inquiry->recognize(RecognizedParameters::recognized(['environment' => 'immersion']), new ParameterSource(ParameterStrategy::Model), $this->now());
        $inquiry->fail('timeout', $this->now());
        $inquiry->pullEvents(); // слить событие создания

        $inquiry->requestReprocessing($this->now());

        self::assertSame(InquiryStatus::Received, $inquiry->getStatus());
        self::assertNull($inquiry->getFailureReason());
        self::assertNull($inquiry->getRecognizedParameters());
        self::assertNull($inquiry->getParameterSource());

        $events = $inquiry->pullEvents();
        self::assertCount(1, $events);
        self::assertInstanceOf(InquirySubmitted::class, $events[0]);
    }

    public function test_retry_from_stuck_received_stays_received_and_reraises(): void
    {
        $inquiry = $this->received();
        $inquiry->pullEvents(); // слить событие создания

        $inquiry->requestReprocessing($this->now());

        self::assertSame(InquiryStatus::Received, $inquiry->getStatus());
        self::assertCount(1, $inquiry->pullEvents());
    }

    public function test_retry_rejected_on_recognized(): void
    {
        $inquiry = $this->received();
        $inquiry->recognize(RecognizedParameters::recognized(['environment' => 'immersion']), new ParameterSource(ParameterStrategy::Model), $this->now());

        $this->expectException(AppException::class);
        $inquiry->requestReprocessing($this->now());
    }

    public function test_retry_rejected_on_approved(): void
    {
        $this->expectException(AppException::class);
        $this->approved()->requestReprocessing($this->now());
    }

    private function received(): Inquiry
    {
        return new Inquiry(Uuid::v4(), InquiryType::SystemSelection, 'owner', 'нужна система для этанола', new StringCollection(), $this->now());
    }

    private function approved(): Inquiry
    {
        $inquiry = $this->received();
        $inquiry->recognize(RecognizedParameters::recognized([]), new ParameterSource(ParameterStrategy::Model), $this->now());
        $inquiry->approve('engineer', $this->now());

        return $inquiry;
    }

    private function now(): \DateTimeImmutable
    {
        return new \DateTimeImmutable(self::NOW);
    }
}
