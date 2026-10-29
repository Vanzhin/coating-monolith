<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Service;

/**
 * Отпечаток собранного входа для кэша точного дубликата: нормализуем (трим + схлопывание пробелов,
 * чтобы косметика переносов/кодировок не мешала) и sha256. Чистая функция — одинаковый текст даёт
 * одинаковый хеш, модель/БД не участвуют. Один источник хеша для записи и для резолва близнеца.
 */
final class ContentFingerprinter
{
    public function hash(string $text): string
    {
        $normalized = trim((string) preg_replace('/\s+/u', ' ', $text));

        return hash('sha256', $normalized);
    }
}
