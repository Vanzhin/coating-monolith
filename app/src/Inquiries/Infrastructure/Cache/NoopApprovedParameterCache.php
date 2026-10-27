<?php

declare(strict_types=1);

namespace App\Inquiries\Infrastructure\Cache;

use App\Inquiries\Application\DTO\CacheHit;
use App\Inquiries\Application\Port\ApprovedParameterCache;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;

/**
 * Заглушка кэша: всегда промах (движок всегда идёт к модели), индексация — no-op. Форма движка
 * правильная с первого дня; настоящая реализация (отпечаток + проекция) заменит её задачей кэша,
 * сам движок не трогая.
 */
final class NoopApprovedParameterCache implements ApprovedParameterCache
{
    public function lookup(InquiryType $type, string $text): ?CacheHit
    {
        return null;
    }

    public function index(Inquiry $inquiry): void
    {
    }
}
