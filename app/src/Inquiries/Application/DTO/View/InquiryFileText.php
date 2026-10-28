<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO\View;

/**
 * Извлечённый текст одного вложения для модалки «текст целиком»: имя, статус (извлечён/скан/ошибка),
 * сам текст (пустой, если не извлечён). Пере-извлекается по запросу, в БД не хранится.
 */
final readonly class InquiryFileText
{
    public function __construct(public string $name, public string $statusLabel, public string $text)
    {
    }
}
