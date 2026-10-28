<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Строка очереди запросов: статус + тип + краткое содержание (превью текста или число вложений) +
 * сколько подобрано + когда. Лёгкая — без распознанных параметров и снимка целиком.
 */
final readonly class InquiryListItemView
{
    public function __construct(
        public string $id,
        public string $typeLabel,
        public string $statusValue,
        public string $statusLabel,
        public string $summary,
        public ?int $answerTotal,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
