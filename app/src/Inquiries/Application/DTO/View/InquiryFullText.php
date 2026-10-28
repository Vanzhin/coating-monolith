<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Полный вход запроса для модалки: тело + извлечённый текст по каждому вложению. Собирается лениво
 * (пере-извлечение), чтобы не грузить блоб в карточку.
 */
final readonly class InquiryFullText
{
    /** @param list<InquiryFileText> $files */
    public function __construct(public string $body, public array $files)
    {
    }
}
