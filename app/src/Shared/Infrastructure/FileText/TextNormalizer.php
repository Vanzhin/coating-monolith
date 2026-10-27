<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\FileText;

/**
 * Приводит сырой текст к безопасному для хранения/модели виду: чинит битые UTF-8 байты
 * и режет по лимиту символов (safety-cap от гигантских вложений), сообщая, была ли обрезка.
 */
final class TextNormalizer
{
    /** @return array{text: string, truncated: bool} */
    public static function normalize(string $raw, int $maxChars): array
    {
        $text = mb_scrub($raw, 'UTF-8');

        if (mb_strlen($text) <= $maxChars) {
            return ['text' => $text, 'truncated' => false];
        }

        return ['text' => mb_substr($text, 0, $maxChars), 'truncated' => true];
    }

    /** Число непробельных символов — для порога «пусто → скан». */
    public static function nonWhitespaceLength(string $text): int
    {
        return mb_strlen((string) preg_replace('/\s+/u', '', $text));
    }
}
