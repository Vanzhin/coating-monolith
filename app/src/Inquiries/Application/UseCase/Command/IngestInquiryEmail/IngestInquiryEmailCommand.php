<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\IngestInquiryEmail;

use App\Shared\Application\Command\Command;

/**
 * Приём письма как запроса на подбор: отправитель + тема/тело + уже застейдженные вложения. Хендлер
 * резолвит отправителя в пользователя (через шину): нашёл → заводит запрос (владелец = пользователь),
 * не нашёл → skipped (запрос не заводим, ответом НЕ отвечаем — просто выход).
 */
final readonly class IngestInquiryEmailCommand extends Command
{
    /** @param list<string> $attachmentFileIds */
    public function __construct(
        public string $fromEmail,
        public string $subject,
        public string $body,
        public array $attachmentFileIds = [],
    ) {
    }
}
