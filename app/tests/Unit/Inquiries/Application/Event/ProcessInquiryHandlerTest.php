<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Application\Event;

use App\Inquiries\Application\Event\ProcessInquiryHandler;
use App\Inquiries\Application\Service\InquiryProcessor;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryStatus;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterSource;
use App\Inquiries\Domain\Aggregate\Inquiry\ParameterStrategy;
use App\Inquiries\Domain\Aggregate\Inquiry\RecognizedParameters;
use App\Inquiries\Domain\Event\InquirySubmitted;
use App\Inquiries\Domain\Repository\InquiryRepositoryInterface;
use App\Shared\Domain\Aggregate\Collection\StringCollection;
use App\Shared\Infrastructure\Exception\AppException;
use App\Tests\Support\Inquiries\InMemoryInquiryRepository;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\Uid\Uuid;

final class ProcessInquiryHandlerTest extends TestCase
{
    public function test_routes_to_processor_by_type_and_saves(): void
    {
        $inquiry = $this->received();
        $repo = new InMemoryInquiryRepository($inquiry);

        $this->handler([$this->successProcessor()], $repo)(new InquirySubmitted($inquiry->getId()));

        self::assertSame(InquiryStatus::Recognized, $inquiry->getStatus());
        self::assertSame(1, $repo->saveCount);
    }

    public function test_processor_failure_marks_failed_with_reason(): void
    {
        $inquiry = $this->received();
        $repo = new InMemoryInquiryRepository($inquiry);

        $this->handler([$this->throwingProcessor()], $repo)(new InquirySubmitted($inquiry->getId()));

        self::assertSame(InquiryStatus::Failed, $inquiry->getStatus());
        self::assertSame('модель недоступна', $inquiry->getFailureReason());
        self::assertSame(1, $repo->saveCount);
    }

    public function test_no_processor_for_type_marks_failed(): void
    {
        $inquiry = $this->received();
        $repo = new InMemoryInquiryRepository($inquiry);

        $this->handler([], $repo)(new InquirySubmitted($inquiry->getId()));

        self::assertSame(InquiryStatus::Failed, $inquiry->getStatus());
        self::assertStringContainsString('Нет обработчика', (string) $inquiry->getFailureReason());
        self::assertSame(1, $repo->saveCount);
    }

    public function test_non_app_exception_hides_internal_detail(): void
    {
        $inquiry = $this->received();
        $repo = new InMemoryInquiryRepository($inquiry);
        $processor = new class implements InquiryProcessor {
            public function supportedType(): InquiryType
            {
                return InquiryType::SystemSelection;
            }

            public function process(Inquiry $inquiry): void
            {
                throw new \RuntimeException('SQLSTATE[08006] connection refused at 10.0.0.1');
            }
        };

        $this->handler([$processor], $repo)(new InquirySubmitted($inquiry->getId()));

        self::assertSame(InquiryStatus::Failed, $inquiry->getStatus());
        self::assertSame('Внутренняя ошибка обработки.', $inquiry->getFailureReason());
    }

    public function test_already_processed_is_skipped(): void
    {
        $inquiry = $this->received();
        $inquiry->recognize(new RecognizedParameters([]), new ParameterSource(ParameterStrategy::Model), new \DateTimeImmutable());
        $repo = new InMemoryInquiryRepository($inquiry);

        // Диспетчер гоняет только Received; распознанный уже обработан — процессор звать нельзя.
        // Процессор роняет тест, если его позвали, — доказываем, что обработка пропущена.
        $this->handler([$this->throwingProcessor()], $repo)(new InquirySubmitted($inquiry->getId()));

        self::assertSame(InquiryStatus::Recognized, $inquiry->getStatus());
        self::assertSame(0, $repo->saveCount);
    }

    public function test_missing_inquiry_is_noop(): void
    {
        $repo = new InMemoryInquiryRepository();

        $this->handler([$this->successProcessor()], $repo)(new InquirySubmitted('missing'));

        self::assertSame(0, $repo->saveCount);
    }

    private function received(): Inquiry
    {
        return new Inquiry(Uuid::v4(), InquiryType::SystemSelection, 'owner', 'текст', new StringCollection(), new \DateTimeImmutable());
    }

    /** @param list<InquiryProcessor> $processors */
    private function handler(array $processors, InquiryRepositoryInterface $repo): ProcessInquiryHandler
    {
        return new ProcessInquiryHandler($processors, $repo, new NullLogger());
    }

    private function successProcessor(): InquiryProcessor
    {
        return new class implements InquiryProcessor {
            public function supportedType(): InquiryType
            {
                return InquiryType::SystemSelection;
            }

            public function process(Inquiry $inquiry): void
            {
                $inquiry->recognize(new RecognizedParameters([]), new ParameterSource(ParameterStrategy::Model), new \DateTimeImmutable());
            }
        };
    }

    private function throwingProcessor(): InquiryProcessor
    {
        return new class implements InquiryProcessor {
            public function supportedType(): InquiryType
            {
                return InquiryType::SystemSelection;
            }

            public function process(Inquiry $inquiry): void
            {
                throw new AppException('модель недоступна');
            }
        };
    }
}
