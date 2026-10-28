<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Выбранный автор для чипа фильтра: id (ulid) + подпись (email). Имя дотягивается из Users батчем.
 */
final readonly class InquiryAuthorView
{
    public function __construct(
        public string $id,
        public string $label,
    ) {
    }
}
