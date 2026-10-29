<?php

declare(strict_types=1);

namespace App\Tests\Support\Mail;

use App\Shared\Domain\Mail\ImapAccount;
use App\Shared\Domain\Mail\IncomingMessage;
use App\Shared\Domain\Mail\MailboxReader;

/**
 * Фейк читателя ящика для юнит-тестов: отдаёт заранее заданные письма и записывает uid'ы, помеченные
 * \Seen — чтобы проверять, что пометка ставится только на успешно обработанных.
 */
final class RecordingMailboxReader implements MailboxReader
{
    /** @var list<string> */
    public array $seen = [];

    /** @param list<IncomingMessage> $messages */
    public function __construct(private readonly array $messages)
    {
    }

    public function fetchUnseen(ImapAccount $account, int $limit): array
    {
        return $this->messages;
    }

    public function markSeen(ImapAccount $account, string $uid): void
    {
        $this->seen[] = $uid;
    }
}
