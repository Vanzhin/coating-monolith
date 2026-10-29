<?php

declare(strict_types=1);

namespace App\Inquiries\Application\UseCase\Command\IngestInquiryEmail;

/**
 * Итог приёма письма: либо запрос заведён (есть inquiryId), либо пропущен — отправитель не пользователь
 * системы (или без прав). При пропуске ничего не заводим и НЕ отвечаем письмом, просто выходим.
 */
final readonly class IngestInquiryEmailResult
{
    private function __construct(
        public bool $skipped,
        public ?string $inquiryId,
    ) {
    }

    public static function created(string $inquiryId): self
    {
        return new self(false, $inquiryId);
    }

    public static function skipped(): self
    {
        return new self(true, null);
    }
}
