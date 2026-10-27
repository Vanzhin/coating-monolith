<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Готовый текст запроса, уходящий в извлечение (склейка тело+вложения — в движке, план 3).
 * Отдельный тип, а не голая строка: даёт смысл параметру и место для будущих метаданных
 * (какие части из тела/вложений) без смены сигнатуры порта.
 */
final readonly class InquiryText
{
    public function __construct(public string $text)
    {
    }
}
