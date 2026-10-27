<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Port;

use App\Inquiries\Application\DTO\CacheHit;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;

/**
 * Кэш одобренных параметров: по тексту запроса отдаёт готовые параметры похожего ОДОБРЕННОГО запроса
 * (модель звать не нужно) либо null. Отпечаток/эмбеддинг/проекция — целиком ВНУТРИ реализации, движок
 * о них не знает. index() добавляет одобренный запрос как топливо. Сейчас — no-op (всегда промах),
 * настоящая реализация придёт задачей кэша.
 */
interface ApprovedParameterCache
{
    public function lookup(InquiryType $type, string $text): ?CacheHit;

    public function index(Inquiry $inquiry): void;
}
