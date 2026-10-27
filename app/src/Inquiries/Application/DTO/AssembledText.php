<?php

declare(strict_types=1);

namespace App\Inquiries\Application\DTO;

/**
 * Склеенный текст запроса (тело + текст вложений) + предупреждения сборки (скан/не прочитан/
 * обрезан). Готов уйти в извлечение; предупреждения осядут в Inquiry::noteWarnings.
 */
final readonly class AssembledText
{
    /** @param list<string> $warnings */
    public function __construct(public string $text, public array $warnings = [])
    {
    }
}
