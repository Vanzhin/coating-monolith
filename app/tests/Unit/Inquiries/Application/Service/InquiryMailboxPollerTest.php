<?php

declare(strict_types=1);

namespace App\Tests\Unit\Inquiries\Application\Service;

use App\Inquiries\Application\Service\InquiryMailboxPoller;
use App\Inquiries\Application\UseCase\Command\IngestInquiryEmail\IngestInquiryEmailCommand;
use App\Inquiries\Application\UseCase\Command\IngestInquiryEmail\IngestInquiryEmailResult;
use App\Shared\Application\Command\CommandBusInterface;
use App\Shared\Application\Command\CommandInterface;
use App\Shared\Domain\Mail\ImapAccount;
use App\Shared\Domain\Mail\IncomingMessage;
use App\Tests\Support\Mail\RecordingMailboxReader;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class InquiryMailboxPollerTest extends TestCase
{
    public function test_not_configured_is_noop(): void
    {
        $reader = $this->reader([]);
        $poller = new InquiryMailboxPoller($reader, new ImapAccount('', 993, 'ssl', '', ''), $this->bus(static fn () => null), new NullLogger());

        $summary = $poller->poll(10);

        self::assertFalse($summary['configured']);
        self::assertSame([], $reader->seen);
    }

    public function test_creates_skips_and_marks_seen_only_on_success(): void
    {
        $reader = $this->reader([
            new IncomingMessage('1', 'known@example.com', 'тема', 'тело'),
            new IncomingMessage('2', 'unknown@example.com', 'тема', 'тело'),
            new IncomingMessage('3', 'boom@example.com', 'тема', 'тело'),
        ]);
        $bus = $this->bus(static function (CommandInterface $c): IngestInquiryEmailResult {
            \assert($c instanceof IngestInquiryEmailCommand);

            return match ($c->fromEmail) {
                'known@example.com' => IngestInquiryEmailResult::created('inq-1'),
                'unknown@example.com' => IngestInquiryEmailResult::skipped(),
                default => throw new \RuntimeException('сбой БД'),
            };
        });

        $poller = new InquiryMailboxPoller($reader, $this->account(), $bus, new NullLogger());

        $summary = $poller->poll(10);

        self::assertSame(['configured' => true, 'processed' => 3, 'created' => 1, 'skipped' => 1], $summary);
        self::assertSame(['1', '2'], $reader->seen); // упавшее (uid 3) НЕ помечено — переиграется
    }

    private function account(): ImapAccount
    {
        return new ImapAccount('imap.example.com', 993, 'ssl', 'prompter@example.com', 'secret');
    }

    /** @param list<IncomingMessage> $messages */
    private function reader(array $messages): RecordingMailboxReader
    {
        return new RecordingMailboxReader($messages);
    }

    private function bus(callable $handler): CommandBusInterface
    {
        $bus = $this->createMock(CommandBusInterface::class);
        $bus->method('execute')->willReturnCallback($handler);

        return $bus;
    }
}
