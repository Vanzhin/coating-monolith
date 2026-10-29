<?php

declare(strict_types=1);

namespace App\Inquiries\Application\Port;

use App\Inquiries\Application\DTO\CacheHit;
use App\Inquiries\Domain\Aggregate\Inquiry\Inquiry;
use App\Inquiries\Domain\Aggregate\Inquiry\InquiryType;

/**
 * Кэш параметров: по тексту запроса отдаёт готовые параметры уже распознанного/одобренного близнеца
 * (модель звать не нужно) либо null. Как ищется близнец (точный отпечаток сейчас, похожесть/эмбеддинг
 * потом) — целиком ВНУТРИ реализации, движок о механике не знает. index() — хук «добавить запрос как
 * топливо» (текущая hash-реализация отпечаток пишет при распознавании, так что для неё это no-op).
 */
interface ApprovedParameterCache
{
    public function lookup(InquiryType $type, string $text): ?CacheHit;

    public function index(Inquiry $inquiry): void;
}
