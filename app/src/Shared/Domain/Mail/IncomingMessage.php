<?php

declare(strict_types=1);

namespace App\Shared\Domain\Mail;

/**
 * Входящее письмо, вычитанное из ящика: серверный uid (для простановки \Seen), отправитель, тема,
 * тело (plain) и вложения. Нейтрально к потребителю — Inquiries/любая фича трактует сами.
 */
final readonly class IncomingMessage
{
    /** @param list<IncomingAttachment> $attachments */
    public function __construct(
        public string $uid,
        public string $fromEmail,
        public string $subject,
        public string $body,
        public array $attachments = [],
    ) {
    }
}
