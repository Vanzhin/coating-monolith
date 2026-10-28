<?php

declare(strict_types=1);

namespace App\Shared\Infrastructure\Http;

/**
 * Режет тело запроса/ответа до лимита для аудита: логи не должны раздуваться большими
 * ответами. Возвращает обрезанный текст и флаг, что обрезка была. Границу режем по
 * байту, но не разрывая UTF-8 символ (mb_strcut), чтобы в БД не попадала битая строка.
 */
final class BodyCapper
{
    public const DEFAULT_MAX_BYTES = 16384;

    /**
     * @return array{text: ?string, truncated: bool}
     */
    public static function cap(?string $body, int $maxBytes = self::DEFAULT_MAX_BYTES): array
    {
        if (null === $body) {
            return ['text' => null, 'truncated' => false];
        }

        if (\strlen($body) <= $maxBytes) {
            return ['text' => $body, 'truncated' => false];
        }

        return ['text' => mb_strcut($body, 0, $maxBytes, 'UTF-8'), 'truncated' => true];
    }
}
