<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\CreateInquiry;

use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;
use App\Shared\Application\Command\Command;

/**
 * Завести запрос: тип, текст и ссылки на уже загруженные файлы (Shared/File). Владелец — переданный
 * ownerId в приоритете (напр. почтовый адаптер резолвит отправителя), иначе текущий актор (веб/консоль).
 * Обработку запускает доменное событие InquirySubmitted, не эта команда.
 */
final readonly class CreateInquiryCommand extends Command
{
    /** @param list<string> $attachmentFileIds */
    public function __construct(
        public InquiryType $type,
        public string $rawText,
        public array $attachmentFileIds = [],
        public ?string $ownerId = null,
    ) {
    }
}
