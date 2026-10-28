<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Строка очереди запросов: статус + тип + краткое содержание (превью текста или число вложений) + когда.
 * Счётчик подобранного не держим — системы это живой результат параметров, не состояние запроса.
 */
final readonly class InquiryListItemView
{
    public function __construct(
        public string $id,
        public string $typeLabel,
        public string $statusValue,
        public string $statusLabel,
        public string $summary,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
