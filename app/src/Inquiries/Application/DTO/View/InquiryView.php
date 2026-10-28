<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

use App\Coatings\Application\DTO\CoatingSystems\FilterFacetLabel;

/**
 * Запрос для экрана-карточки: статус + вход + распознанные параметры человекочитаемыми подписями
 * (Coatings описывает фасеты, вещества резолвятся в имена) + предупреждения + провенанс. Подобранные
 * системы сюда НЕ входят — они живой результат параметров (InquiryResultsView), не состояние запроса.
 */
final readonly class InquiryView
{
    /**
     * @param list<InquiryAttachmentView> $attachments
     * @param list<FilterFacetLabel>      $paramRows
     * @param list<string>                $warnings
     */
    public function __construct(
        public string $id,
        public string $typeLabel,
        public string $statusValue,
        public string $statusLabel,
        public string $rawText,
        public array $attachments,
        public array $paramRows,
        public array $warnings,
        public ?string $failureReason,
        public string $comment,
        public ?string $sourceStrategyLabel,
        public \DateTimeImmutable $createdAt,
    ) {
    }
}
